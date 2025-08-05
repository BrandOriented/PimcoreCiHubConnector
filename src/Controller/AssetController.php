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

use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\InvalidParameterException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Helper\AssetHelper;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\RestHelperTrait;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Pimcore\Model\Asset;
use Pimcore\Model\Element\Service;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: ['/datahub/rest/{config}/asset', '/pimcore-datahub-webservices/simplerest/{config}'], name: 'datahub_rest_endpoints_asset_')]
#[Security(name: 'Bearer')]
#[OA\Tag(name: 'Asset')]
final class AssetController extends BaseEndpointController
{
    use RestHelperTrait;

    #[OA\Post(
        description: 'Simple method to create and upload asset',
        summary: 'Add asset',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'file',
                            type: 'string',
                            format: 'binary'
                        ),
                    ]
                )
            )
        ),
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
                description: 'Type of elements – asset or object (not used, will be removed).',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asset', 'object']
                )
            ),
            new OA\Parameter(
                name: 'parentId',
                description: 'Parent ID of the element.',
                in: 'query',
                required: false,
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
                            property: 'id',
                            description: 'Asset ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'path',
                            description: 'Asset path',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'success',
                            description: 'Success response',
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
    #[OA\Tag(name: 'Asset')]
    #[Route(name: 'upload', methods: ['POST'])]
    public function add(): Response
    {
        $parentId = $this->request->query->getInt('parentId');
        try {
            $this->checkRequiredParameters(['parentId' => $parentId]);
        } catch (InvalidParameterException $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
        try {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $this->request->files->get('file');
            if (!$uploadedFile) {
                return new JsonResponse(['success' => false, 'message' => 'InvalidParameter: file']);
            }
            $sourcePath = $uploadedFile->getRealPath();
            $filename = $uploadedFile->getClientOriginalName();
            $filename = Service::getValidKey($filename, 'asset');

            if ('' === $filename) {
                return new JsonResponse(['success' => false, 'message' => 'The filename of the asset is empty']);
            }

            $parentAsset = Asset::getById($this->request->query->getInt('parentId'));
            if (!$parentAsset instanceof Asset\Folder) {
                return new JsonResponse(['success' => false, 'message' => 'Parent does not exist']);
            }

            if (is_file($sourcePath) && filesize($sourcePath) < 1) {
                return new JsonResponse(['success' => false, 'message' => 'File is empty!']);
            } elseif (!is_file($sourcePath)) {
                return new JsonResponse(['success' => false, 'message' => 'Something went wrong, please check upload_max_filesize and post_max_size in your php.ini as well as the write permissions of your temporary directories.']);
            }

            if (!$parentAsset->isAllowed('create', $this->user)) {
                return new JsonResponse(['success' => false, 'message' => 'Missing the permission to create new assets in the folder: '.$parentAsset->getRealFullPath()]);
            }

            $asset = Asset::create($parentAsset->getId(), [
                'filename' => $filename,
                'sourcePath' => $sourcePath,
                'userOwner' => $this->user->getId(),
                'userModification' => $this->user->getId(),
            ]);

            @unlink($sourcePath);

            return new JsonResponse([
                'success' => true,
                'asset' => [
                    'id' => $asset->getId(),
                    'path' => $asset->getFullPath(),
                    'type' => $asset->getType(),
                ],
            ]);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    #[OA\Post(
        description: 'Simple method to update and upload asset',
        summary: 'Update asset',
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(
                            property: 'file',
                            type: 'string',
                            format: 'binary'
                        ),
                    ]
                )
            )
        ),
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
                description: 'Type of elements – asset or object (not used, will be removed).',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asset', 'object']
                )
            ),
            new OA\Parameter(
                name: 'id',
                description: 'ID of the element.',
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
                            property: 'id',
                            description: 'Asset ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'path',
                            description: 'Asset path',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'success',
                            description: 'Success response',
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
    #[OA\Tag(name: 'Asset')]
    #[Route('/update', name: 'update', methods: ['POST'])]
    public function update(
        TranslatorInterface $translator,
        AssetHelper $assetHelper
    ): Response {
        $id = $this->request->query->getInt('id');
        try {
            $this->checkRequiredParameters(['id' => $id]);
        } catch (InvalidParameterException $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        try {
            $asset = Asset::getById($id);
            if ($asset instanceof Asset && !$asset instanceof Asset\Folder) {
                if ($asset->isAllowed('create', $this->user)) {
                    /** @var UploadedFile $uploadedFile */
                    $uploadedFile = $this->request->files->get('file');
                    if (!$uploadedFile) {
                        return new JsonResponse(['success' => false, 'message' => 'InvalidParameter file']);
                    }
                    $sourcePath = $uploadedFile->getRealPath();
                    $filename = $uploadedFile->getClientOriginalName();
                    $filename = Service::getValidKey($filename, 'asset');

                    if ('' === $filename) {
                        return new JsonResponse(['success' => false, 'message' => 'The filename of the asset is empty']);
                    }

                    if (is_file($sourcePath) && filesize($sourcePath) < 1) {
                        return new JsonResponse(['success' => false, 'message' => 'File is empty!']);
                    } elseif (!is_file($sourcePath)) {
                        return new JsonResponse(['success' => false, 'message' => 'Something went wrong, please check upload_max_filesize and post_max_size in your php.ini as well as the write permissions of your temporary directories.']);
                    }

                    return $assetHelper->updateAsset($asset, $sourcePath, $filename, $this->user, $translator);
                } else {
                    return new JsonResponse(['success' => false, 'message' => 'Missing the permission to overwrite asset: '.$asset->getId()]);
                }
            } else {
                return new JsonResponse(['success' => false, 'message' => 'Asset with id ['.$id."] doesn't exist"]);
            }
        } catch (\Exception $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
