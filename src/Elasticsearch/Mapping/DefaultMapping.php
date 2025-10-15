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

abstract class DefaultMapping implements MappingInterface
{
    public const INTERNAL_ENTITY_TYPE = '__internal_entity_type';
    public const INTERNAL_CHECKSUM = '__internal_checksum';
    public const INTERNAL_ORIGINAL_FULL_PATH = '__internal_original_full_path';

    protected array $mappingTemplate =  [
        'dynamic_templates' => [
            [
                'strings' => [
                    'match_mapping_type' => 'string',
                    'mapping' => [
                        'type' => 'keyword',
                        'fields' => [
                            'analyzed' => [
                                'type' => 'text',
                                'analyzer' => 'datahub_ngram_analyzer',
                                'search_analyzer' => 'datahub_whitespace_analyzer'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    protected function createSystemAttributesMapping(): array
    {
        return [
            'type' => 'object',
            'dynamic' => false,
            'properties' => [
                'id' => ['type' => 'long'],
                'key' => [
                    'type' => 'keyword',
                    'fields' => [
                        'analyzed' => [
                            'type' => 'text',
                            'analyzer' => 'datahub_ngram_analyzer',
                            'search_analyzer' => 'datahub_whitespace_analyzer'
                        ]
                    ]
                ],
                'fullPath' => [
                    'type' => 'keyword',
                    'fields' => [
                        'path_analyzed' => [
                            'type' => 'text',
                            'analyzer' => 'path_analyzer',
                            'search_analyzer' => 'keyword'
                        ]
                    ]
                ],
                'type' => ['type' => 'keyword'],
                'subtype' => ['type' => 'keyword'],
                'className' => ['type' => 'keyword'],
                'parentId' => ['type' => 'long'],
                'hasChildren' => ['type' => 'boolean'],
                'creationDate' => ['type' => 'date'],
                'modificationDate' => ['type' => 'date'],
                self::INTERNAL_ENTITY_TYPE => ['type' => 'keyword'],
                self::INTERNAL_CHECKSUM => ['type' => 'keyword'],
                self::INTERNAL_ORIGINAL_FULL_PATH => [
                    'type' => 'keyword',
                    'fields' => [
                        'path_analyzed' => [
                            'type' => 'text',
                            'analyzer' => 'path_analyzer',
                            'search_analyzer' => 'keyword'
                        ]
                    ]
                ],
            ]
        ];
    }

    /**
     * @return array<string, array>
     */
    public function getBinaryDataProperties(): array
    {
        return [
            'checksum' => [
                'type' => 'keyword',
            ],
            'path' => [
                'type' => 'keyword',
            ],
            'filename' => [
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
        ];
    }

    /**
     * @return array<string, array>
     */
    public function getImageProperties(): array
    {
        return [
            'properties' => [
                'id' => [
                    'type' => 'long',
                ],
                'type' => [
                    'type' => 'constant_keyword',
                ],
                'binaryData' => [
                    'dynamic' => 'false',
                    'type' => 'object',
                    'properties' => $this->getBinaryDataProperties(),
                ],
            ],
        ];
    }
}
