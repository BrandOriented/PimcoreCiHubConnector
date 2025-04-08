<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index;

use CIHub\Bundle\SimpleRESTAdapterBundle\Provider\ProviderInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;

final readonly class IndexPersistenceService
{
    /**
     * @param array<string, string|array> $indexSettings
     */
    public function __construct(
        private Client                         $client,
        private DataHubConfigurationRepository $dataHubConfigurationRepository,
        private ProviderInterface              $assetProvider,
        private ProviderInterface              $dataObjectProvider,
        private array                          $indexSettings
    ) {
    }

    /**
     * Checks whether the given alias name exists or not.
     *
     * @param string $name – A comma-separated list of alias names to return
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function aliasExists(string $name): bool
    {
        $params = [
            'name' => $name,
        ];

        return $this->client->indices()->existsAlias($params)->asBool();
    }

    /**
     * Creates or updates an index alias for the given index/indices and name.
     *
     * @param string $index – A comma-separated list of index names the alias should point to (supports wildcards);
     *                      use `_all` to perform the operation on all indices
     * @param string $name  – The name of the alias to be created or updated
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function createAlias(string $index, string $name): array
    {
        $params = [
            'index' => $index,
            'name' => $name,
        ];

        return $this->client->indices()->putAlias($params)->asArray();
    }

    /**
     * Creates a new index either with or without settings/mappings.
     *
     * @param string $name    – The name of the index
     * @param array  $mapping – The mapping for the index
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     * @throws \Exception
     */
    public function createIndex(string $name, array $mapping = []): array
    {
        $indexSettings = [
            'number_of_replicas' => 0,
            'max_ngram_diff' => 30,
            'analysis' => [
                'analyzer' => [
                    'path_analyzer' => [
                        'tokenizer' => 'path_tokenizer'
                    ],
                    'datahub_ngram_analyzer' => [
                        'tokenizer' => 'datahub_ngram_tokenizer',
                        'filter' => ['lowercase']
                    ],
                    'datahub_whitespace_analyzer' => [
                        'tokenizer' => 'datahub_whitespace_tokenizer',
                        'filter' => ['lowercase']
                    ]

                ],
                'tokenizer' => [
                    'path_tokenizer' => [
                        'type' => 'path_hierarchy',
                    ],
                    'datahub_ngram_tokenizer' => [
                        'type' => 'ngram',
                        'min_gram' => 3,
                        'max_gram' => 25,
                        'token_chars' => ['letter', 'digit']
                    ],
                    'datahub_whitespace_tokenizer' => [
                        'type' => 'whitespace'
                    ]
                ]
            ]
        ];
        $indexSettings['number_of_shards'] = $this->indexSettings['number_of_shards'];

        $result = $this->client->indices()->create([
            'index' => $name,
            'body' => [
                'mappings' => $mapping,
                'settings' => $indexSettings,
            ],
        ])->asArray();

        if (!$result['acknowledged']) {
            throw new \Exception('Index creation failed. IndexName: '.$name);
        }

        return $result;
    }

    /**
     * Deletes an existing index.
     *
     * @param string $name – A comma-separated list of indices to delete;
     *                     use `_all` or `*` string to delete all indices
     *
     * @return array<string, mixed>
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/index-management-settings.html#action-destructive-requires-name action.destructive_requires_name
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function deleteIndex(string $name): array
    {
        $params = [
            'index' => $name,
        ];

        return $this->client->indices()->delete($params)->asArray();
    }

    /**
     * Deletes an element from an index.
     *
     * @param int    $elementId – The ID of a Pimcore element (asset or object)
     * @param string $indexName – The name of the index to delete the item from
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function delete(int $elementId, string $indexName): array
    {
        return $this->client->delete([
            'index' => $indexName,
            'id' => $elementId,
        ])->asArray();
    }

    /**
     * Returns all, one or filtered list of aliases.
     *
     * @param string|null $aliasName – A comma-separated list of alias names to return
     * @param string|null $indexName – A comma-separated list of index names to filter aliases
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function getAlias(string $aliasName = null, string $indexName = null): array
    {
        $params = [];

        if (null !== $aliasName) {
            $params['name'] = $aliasName;
        }

        if (null !== $indexName) {
            $params['index'] = $indexName;
        }

        return $this->client->indices()->getAlias($params)->asArray();
    }

    /**
     * Returns the mapping(s) of the given index/indices.
     *
     * @param string $indexName – A comma-separated list of index names
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function getMapping(string $indexName): array
    {
        $params = [
            'index' => $indexName,
        ];

        return $this->client->indices()->getMapping($params)->asArray();
    }

    /**
     * Checks whether the given index name exists or not.
     *
     * @param string $name – A comma-separated list of index names
     *
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function indexExists(string $name): bool
    {
        $params = [
            'index' => $name,
        ];

        return $this->client->indices()->exists($params)->asBool();
    }

    /**
     * Refreshes one or more indices. For data streams, the API refreshes the stream’s backing indices.
     *
     * @param string $name – A comma-separated list of index names;
     *                     use `_all` or empty string to perform the operation on all indices
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function refreshIndex(string $name): array
    {
        $params = [
            'index' => $name,
        ];

        return $this->client->indices()->refresh($params)->asArray();
    }

    /**
     * Reindex data from a source index to a destination index.
     *
     * @param string $source – The name of the source index
     * @param string $dest   – The name of the destination index
     *
     * @return array<string, mixed>
     *
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    public function reindex(string $source, string $dest): array
    {
        $params = [
            'body' => [
                'source' => [
                    'index' => $source,
                ],
                'dest' => [
                    'index' => $dest,
                ],
            ],
        ];

        return $this->client->reindex($params)->asArray();
    }

    /**
     * Indexes an element's data or updates the values, if it already exists.
     *
     * @param ElementInterface $element      – A Pimcore element, either asset or object
     * @param string           $endpointName – The endpoint configuration name
     * @param string           $indexName    – The name of the index to update the item
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function update(ElementInterface $element, string $endpointName, string $indexName): array
    {
        $configuration = $this->dataHubConfigurationRepository->findOneByName($endpointName);

        if (!$configuration instanceof Configuration) {
            throw new \InvalidArgumentException(sprintf('No DataHub configuration found for name "%s".', $endpointName));
        }

        $configReader = new ConfigReader($configuration->getConfiguration());

        if ($element instanceof DataObject\AbstractObject) {
            if (!$configReader->isObjectIndexingEnabled()) {
                return [];
            }
            $body = $this->dataObjectProvider->getIndexData($element, $configReader);
        }
        elseif ($element instanceof Asset) {
            if (!$configReader->isAssetIndexingEnabled()) {
                return [];
            }
            $body = $this->assetProvider->getIndexData($element, $configReader);
        }
        else {
            throw new \InvalidArgumentException('This element type is currently not supported.');
        }

        return $this->client->index([
            'index' => $indexName,
            'type' => '_doc',
            'id' => $element->getId(),
            'body' => $body,
        ])->asArray();
    }

    public function bulk(array $params = []) {
        return $this->client->bulk($params);
    }
}
