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

use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\NotFoundException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\RestHelperTrait;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: ['/datahub/rest/{config}/folder', '/pimcore-datahub-webservices/simplerest/{config}'], name: 'datahub_rest_endpoints_')]
#[Security(name: 'Bearer')]
#[OA\Tag(name: 'Folder')]
final class FolderController extends BaseEndpointController
{
    use RestHelperTrait;

    /**
     * @throws ValidationException
     */
    #[Route('', name: 'folder_create', methods: ['POST'])]
    #[OA\Post(
        description: 'Method to create folder by type and ID.',
        summary: 'Create folder for element (eg. Asset, Object)',
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
            new OA\Parameter(
                name: 'type',
                description: 'Type of elements – asset or object.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asset', 'object']
                )
            ),
            new OA\Parameter(
                name: 'parentId',
                description: 'ID of parent element.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
                )
            ),
            new OA\Parameter(
                name: 'name',
                description: 'Name of folder.',
                in: 'query',
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
                            property: 'id',
                            description: 'Folder ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'parentId',
                            description: 'Parent ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'path',
                            description: 'Folder path',
                            type: 'string'
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
    public function create(): Response
    {
        $parent = $this->getParent();
        $folder = match ($parent::class) {
            Folder::class => $this->createAssetFolder($parent),
            DataObject\Folder::class => $this->createObjectFolder($parent),
            default => throw new NotFoundException('Parent type is not supported'),
        };

        return new JsonResponse([
            'id' => $folder->getId(),
            'path' => $folder->getFullPath(),
            'parentId' => $folder->getParentId(),
        ]);
    }

    #[Route('', name: 'folder_delete', methods: ['DELETE'])]
    #[OA\Delete(
        description: 'Method to delete folder by type and ID.',
        summary: 'Delete folder for element (eg. Asset, Object)',
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
            new OA\Parameter(
                name: 'type',
                description: 'Type of elements – asset or object.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asset', 'object']
                )
            ),
            new OA\Parameter(
                name: 'id',
                description: 'ID of element.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
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
                            property: 'success',
                            description: 'Success status',
                            type: 'boolean'
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
    public function delete(): Response
    {
        try {
            $element = $this->getElementByIdType();
        } catch (NotFoundException) {
            return new JsonResponse(['success' => false, 'message' => 'Folder with id ['.$this->request->query->getInt('id')."] doesn't exist"]);
        }

        $success = match ($element::class) {
            Folder::class => $this->deleteAssetFolder($element),
            DataObject\Folder::class => $this->deleteObjectFolder($element),
            default => throw new NotFoundException('Type is not supported'),
        };

        return new JsonResponse([
            'success' => $success,
        ]);
    }
}
