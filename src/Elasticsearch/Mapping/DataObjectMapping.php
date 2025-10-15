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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Mapping;

final class DataObjectMapping extends DefaultMapping
{
    public function generate(array $objectClassConfig = []): array
    {
        if ([] === $objectClassConfig) {
            throw new \RuntimeException('No DataObject class configuration provided.');
        }

        $mappings = $this->mappingTemplate;
        $mappings['properties'] = [
            'data' => [
                'dynamic' => 'true',
                'properties' => $this->generateDataProperties($objectClassConfig),
            ],
            'system' => [
                'dynamic' => 'false',
                'properties' => [
                    'id' => [
                        'type' => 'long',
                    ],
                    'key' => [
                        'type' => 'keyword',
                        'fields' => [
                            'analyzed' => [
                                'type' => 'text',
                                'term_vector' => 'yes',
                                'analyzer' => 'datahub_ngram_analyzer',
                                'search_analyzer' => 'datahub_whitespace_analyzer',
                            ],
                        ],
                    ],
                    'fullPath' => [
                        'type' => 'keyword',
                        'fields' => [
                            'analyzed' => [
                                'type' => 'text',
                                'term_vector' => 'yes',
                                'analyzer' => 'datahub_ngram_analyzer',
                                'search_analyzer' => 'datahub_whitespace_analyzer',
                            ],
                        ],
                    ],
                    'type' => [
                        'type' => 'constant_keyword',
                    ],
                    'parentId' => [
                        'type' => 'keyword',
                    ],
                    'hasChildren' => [
                        'type' => 'boolean',
                    ],
                    'creationDate' => [
                        'type' => 'date',
                    ],
                    'modificationDate' => [
                        'type' => 'date',
                    ],
                    'subtype' => [
                        'type' => 'keyword',
                    ],
                    'className' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
        ];

        return $mappings;
    }

    /**
     * Generates all data properties for the given DataObject class config.
     *
     *
     * @return array<string, array>
     */
    private function generateDataProperties(array $objectClassConfig): array
    {
        $properties = [];
        $columnConfig = $objectClassConfig['columnConfig'] ?? [];

        foreach ($columnConfig as $column) {
            if (true === $column['hidden']) {
                continue;
            }

            $properties[$column['name']] = $this->getPropertiesForFieldConfig($column['fieldConfig']);
        }

        return $properties;
    }

    /**
     * Generates the property definition for a given field config.
     *
     * @param array<string, array|string> $objectClassConfig
     *
     * @return array<string, array|string>
     */
    private function getPropertiesForFieldConfig(array $objectClassConfig): array
    {
        return match ($objectClassConfig['type']) {
            'hotspotimage', 'image' => array_merge($this->getImageProperties(), [
                'dynamic' => 'false',
                'type' => 'object',
            ]),
            'imageGallery' => array_merge($this->getImageProperties(), [
                'dynamic' => 'false',
                'type' => 'nested',
            ]),
            'numeric' => [
                'type' => $objectClassConfig['layout']['integer'] ? 'integer' : 'float',
            ],
            default => [
                'type' => 'keyword',
                'fields' => [
                    'analyzed' => [
                        'type' => 'text',
                        'term_vector' => 'yes',
                        'analyzer' => 'datahub_ngram_analyzer',
                        'search_analyzer' => 'datahub_whitespace_analyzer',
                    ],
                ],
            ],
        };
    }
}
