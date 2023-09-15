<?php
/**
 * Simple REST Adapter.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 * @license    https://github.com/ci-hub-gmbh/SimpleRESTAdapterBundle/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\AccessDeniedException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\ConfigurationNotFoundException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\InvalidParameterException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\AuthManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use ONGR\ElasticsearchDSL\Aggregation\Bucketing\TermsAggregation;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\SimpleQueryStringQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchDSL\Sort\FieldSort;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

abstract class BaseEndpointController extends FrontendController
{
    protected const OPERATOR_MAP = [
        '$and' => BoolQuery::MUST,
        '$not' => BoolQuery::MUST_NOT,
        '$or' => BoolQuery::SHOULD,
    ];

    /**
     * @var string
     */
    protected string $config;

    /**
     * @var bool
     */
    protected bool $includeAggregations = false;

    /**
     * @var int
     */
    protected int $nextPageCursor = 200;

    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @param DataHubConfigurationRepository $configRepository
     * @param LabelExtractorInterface        $labelExtractor
     * @param RequestStack                   $requestStack
     */
    public function __construct(
        private readonly DataHubConfigurationRepository $configRepository,
        private readonly LabelExtractorInterface        $labelExtractor,
        private readonly RequestStack $requestStack,
        protected readonly AuthManager $authManager
    ) {
        $this->request = $this->requestStack->getMainRequest();
        $this->config = $this->request->get('config');
    }

    /**
     * @param Search $search
     */
    public function applySearchSettings(Search $search): void
    {
        $size = (int) $this->request->get('size', 200);
        $pageCursor = (int) $this->request->get('page_cursor', 0);
        $orderBy = $this->request->get('order_by');

        $search->setSize($size);
        $search->setFrom($pageCursor);

        if (null !== $orderBy) {
            $search->addSort(new FieldSort($orderBy));
        }

        $this->nextPageCursor = $pageCursor + $size;
    }

    /**
     * @param Search       $search
     * @param ConfigReader $reader
     */
    protected function applyQueriesAndAggregations(Search $search, ConfigReader $reader): void
    {
        $parentId = intval($this->request->get('parent_id', 1));
        $type = $this->request->get('type', 'object');
        $orderBy = $this->request->get('order_by', null);
        $fulltext = $this->request->get('fulltext_search');
        $filter = json_decode($this->request->get('filter'), true);
        $this->includeAggregations = filter_var(
            $this->request->get('include_aggs', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!empty($fulltext)) {
            $search->addQuery(new SimpleQueryStringQuery($fulltext));
        }

        if (is_array($filter) && !empty($filter)) {
            $this->buildQueryConditions($search, $filter);
        }

        if (true === $this->includeAggregations) {
            $labels = $reader->getLabelSettings();

            foreach ($labels as $label) {
                if (!isset($label['useInAggs']) || !$label['useInAggs']) {
                    continue;
                }

                $field = $label['id'];
                $search->addAggregation(new TermsAggregation($field, $field));
            }
        }


        $query['bool']['filter']['bool']['must'][] = [
            'term' => [
                'system.type' => $type
            ]
        ];
        $query['bool']['filter']['bool']['must'][] = [
            'term' => [
                'system.parentId' => $parentId
            ]
        ];

        $body['query'] = $query;

        $sort = [];

        if ($orderBy) {
            foreach ($orderBy as $field => $order) {
                $sort[] = [
                    $field => [
                        'order' => $order,
                        'missing' => '_last',
                        'unmapped_type' => 'keyword'
                    ]
                ];
            }
        }
        $sort[] = [
            'system.id' => [
                'order' => 'asc'
            ]
        ];
        $body['sort'] = $sort;
    }

    /**
     * @param Search                      $search
     * @param array<string, string|array> $filters
     */
    protected function buildQueryConditions(Search $search, array $filters): void
    {
        foreach ($filters as $key => $value) {
            if (array_key_exists(strtolower($key), self::OPERATOR_MAP)) {
                $operator = self::OPERATOR_MAP[strtolower($key)];

                if (!is_array($value)) {
                    continue;
                }

                foreach ($value as $condition) {
                    if (!is_array($condition)) {
                        continue;
                    }

                    $field = (string) array_key_first($condition);
                    $search->addQuery(new TermQuery($field, $condition[$field]), $operator);
                }
            } else if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (array_key_exists(strtolower($subKey), self::OPERATOR_MAP)) {
                        $subOperator = self::OPERATOR_MAP[strtolower($subKey)];

                        if ($subOperator !== BoolQuery::MUST_NOT) {
                            continue;
                        }

                        $search->addQuery(new TermQuery($key, $subValue), $subOperator);
                    }
                }
            } else {
                $search->addQuery(new TermQuery($key, $value));
            }
        }
    }

    /**
     * @param array<string, string|array> $result
     * @param ConfigReader                $reader
     *
     * @return array<string, string|array>
     */
    protected function buildResponse(array $result, ConfigReader $reader): array
    {
        $response = [];

        if (isset($result['hits']['hits'])) {
            $hitIndices = $items = [];

            foreach ($result['hits']['hits'] as $hit) {
                if (!in_array($hit['_index'], $hitIndices, true)) {
                    $hitIndices[] = $hit['_index'];
                }

                $items[] = $hit['_source'];
            }

            $response = [
                'total_count' => $result['hits']['total']['value'] ?? 0,
                'items' => $items,
            ];

            if ($response['total_count'] > 0) {
                // Page Cursor
                $response['page_cursor'] = $this->nextPageCursor;

                // Aggregations
                if (true === $this->includeAggregations) {
                    $aggs = [];
                    $aggregations = $result['aggregations'] ?? [];

                    foreach ($aggregations as $field => $aggregation) {
                        if (empty($aggregation['buckets'])) {
                            continue;
                        }

                        $aggs[$field]['buckets'] = array_map(static function ($bucket) {
                            return [
                                'key' => $bucket['key'],
                                'element_count' => $bucket['doc_count'],
                            ];
                        }, $aggregation['buckets']);
                    }

                    $response['aggregations'] = $aggs;
                }

                // Labels
                $labels = $this->labelExtractor->extractLabels($hitIndices);
                $response['labels'] = $reader->filterLabelSettings($labels);
            }
        } elseif (isset($result['_index'], $result['_source'])) {
            $response = $result['_source'];

            // Labels
            $labels = $this->labelExtractor->extractLabels([$result['_index']]);
            $response['labels'] = $reader->filterLabelSettings($labels);
        }

        return $response;
    }

    /**
     * @param array<string, string|null> $params
     */
    protected function checkRequiredParameters(array $params): void
    {
        $required = [];

        foreach ($params as $key => $value) {
            if (null !== $value) {
                continue;
            }

            $required[] = $key;
        }

        if (!empty($required)) {
            throw new InvalidParameterException($required);
        }
    }

    /**
     * @return Configuration
     */
    protected function getDataHubConfiguration(): Configuration
    {
        $configuration = $this->configRepository->findOneByName($this->config);

        if (!$configuration instanceof Configuration) {
            throw new ConfigurationNotFoundException($this->config);
        }

        return $configuration;
    }
}
