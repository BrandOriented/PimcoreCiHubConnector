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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\TagsFilterTrait;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Pimcore\Model\Element\Tag;
use Pimcore\Model\Element\Tag\Listing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: ['/datahub/rest/{config}', '/pimcore-datahub-webservices/simplerest/{config}'], name: 'datahub_rest_endpoints_filter')]
#[Security(name: 'Bearer')]
#[OA\Tag(name: 'Search')]
final class FilterController extends BaseEndpointController
{
    use TagsFilterTrait;

    #[Route(path: '/filter', name: 'get', methods: ['GET'])]
    #[OA\Get(
        description: 'Method to load all filters for given config.',
        summary: 'Get filters tree',
        parameters: [
            new OA\Parameter(
                name: 'Authorization',
                description: 'Bearer (in Swagger UI use authorize feature to set header)',
                in: 'header'
            ),
            new OA\Parameter(
                name: 'config',
                description: 'Name of the config.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'total_count',
                            description: 'Total count of available results.',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(
                                        property: 'label',
                                        type: 'string',
                                    ),
                                    new OA\Property(
                                        property: 'items',
                                        type: 'array',
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(
                                                    property: 'label',
                                                    type: 'string',
                                                ),
                                            ],
                                            type: 'object'
                                        )
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Not found'
            ),
            new OA\Response(
                response: 401,
                description: 'Access denied'
            ),
            new OA\Response(
                response: 500,
                description: 'Server error'
            ),
        ],
    )]
    public function get(): JsonResponse
    {
        $listing = new Listing();
        $listing
            ->setCondition('ISNULL(parentId) OR parentId = 0')
            ->setOrderKey('id')
            ->setOrder('ASC');
        $tagsList = $listing->getTags();
        $tagRoot = array_filter($tagsList, static fn (Tag $tag): bool => 0 === $tag->getParentId());
        $tagRoot = array_map(fn (Tag $tag): array => $this->convertTagToArray($tag), $tagRoot);
        $tagRoot = array_filter($tagRoot, static fn (array $item): bool => isset($item['items']));

        return new JsonResponse([
            'total_count' => \count($tagRoot),
            'items' => $this->mergeTopLevelItems($tagRoot),
        ]);
    }
}
