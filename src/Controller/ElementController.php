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

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Helper\AssetHelper;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\RestHelperTrait;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Pimcore\Logger;
use Pimcore\Model\AbstractModel;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\Service;
use Pimcore\Model\Element\Tag;
use Pimcore\Model\Version;
use Pimcore\Model\Version\Listing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: ['/datahub/rest/{config}/element', '/pimcore-datahub-webservices/simplerest/{config}'], name: 'datahub_rest_endpoints_element_')]
#[Security(name: 'Bearer')]
#[OA\Tag(name: 'Element')]
final class ElementController extends BaseEndpointController
{
    use RestHelperTrait;

    /**
     * @throws \Exception
     */
    #[Route('', name: 'get', methods: ['GET'])]
    #[OA\Get(
        description: 'Method to get one single element by type and ID.',
        summary: 'Get Element (eg. Asset, Object)',
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
                            property: 'id',
                            description: 'Element ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'Parent ID',
                            description: 'Parent ID',
                            type: 'integer',
                        ),
                        new OA\Property(
                            property: 'name',
                            description: 'Element name',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'type',
                            description: 'Type of element',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'locked',
                            description: 'Element is locked?',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'tags',
                            description: 'Tags assigned to element',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
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
    public function getElementAction(AssetHelper $assetHelper): JsonResponse
    {
        $this->authManager->checkAuthentication();
        try {
            $configuration = $this->getDataHubConfiguration();
            $configReader = new ConfigReader($configuration->getConfiguration());
        } catch (\Exception $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
        $elementByIdType = $this->getElementByIdType();
        $elementType = $elementByIdType instanceof Asset ? 'asset' : 'object';
        if (!$elementByIdType->isAllowed('view', $this->user)) {
            return new JsonResponse(['success' => false, 'message' => 'Missing the permission to list in the folder: '.$elementByIdType->getRealFullPath()]);
        }
        $tags = Tag::getTagsForElement('asset', $elementByIdType->getId());

        $result = [
            'id' => $elementByIdType->getId(),
            'parentId' => $elementByIdType->getParentId(),
            'name' => $elementByIdType->getKey(),
            'type' => $elementByIdType->getType(),
            'locked' => $assetHelper->isLocked($elementByIdType->getId(), $elementType, $this->user->getId()),
            'tags' => array_map(fn (Tag $tag): array => [
                'label' => $tag->getName(),
            ], $tags),
        ];
        $result = $this->getAssetMetaData($elementByIdType, $result, $configReader);

        return $this->json($result);
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[OA\Delete(
        description: 'Method to delete a single element by type and ID.',
        summary: 'Delete Element (eg. Asset, Object)',
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
                            description: 'Success status.',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'message',
                            description: 'Message.',
                            type: 'string',
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
        $type = $this->request->query->getString('type');
        $elementByIdType = $this->getElementByIdType();
        if ($elementByIdType->isAllowed('delete', $this->user)) {
            $elementByIdType->delete();

            return new JsonResponse([
                'success' => true,
                'message' => $type.' in the folder: '.$elementByIdType->getParent()->getRealFullPath().' was deleted',
            ]);
        }

        return new JsonResponse(['success' => false, 'message' => 'Missing the permission to remove '.$type.' in the folder: '.$elementByIdType->getParent()->getRealFullPath()]);
    }

    #[Route('/version', name: 'version', methods: ['GET'])]
    #[OA\Get(
        description: 'Method to get a specified version of the element by type and ID.',
        summary: 'Get Version of Element (eg. Asset, Object)',
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
                            property: 'id',
                            description: 'Version ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'cid',
                            description: 'Asset ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'ctype',
                            description: 'Object type',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'note',
                            description: 'Version note',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'date',
                            description: 'Timestamp of version creation',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'public',
                            description: 'Version is public?',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'versionCount',
                            description: 'Version sequence number',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'autoSave',
                            description: 'Version is auto-save?',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'user',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(
                                        property: 'name',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'id',
                                        type: 'integer'
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'metadata',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(
                                        property: 'data',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'language',
                                        type: 'string',
                                        nullable: true
                                    ),
                                    new OA\Property(
                                        property: 'name',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'type',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'config',
                                        type: 'string',
                                        nullable: true
                                    ),
                                ]
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
    public function getElementVersion(): Response
    {
        [$element, $version] = $this->getVersion();
        $response = [];
        if ($element->isAllowed('versions', $this->user)) {
            $response = [
                'id' => $version->getId(),
                'cid' => $element->getId(),
                'note' => $version->getNote(),
                'date' => $version->getDate(),
                'public' => $version->isPublic(),
                'versionCount' => $version->getVersionCount(),
                'autoSave' => $version->isAutoSave(),
                'user' => [
                    'name' => $version->getUser()->getName(),
                    'id' => $version->getUser()->getId(),
                ],
            ];
            if ($element instanceof Asset) {
                $response['fileSize'] = $element->getMetadata();
            }
        }

        return new JsonResponse(['success' => true, 'data' => $response]);
    }

    /**
     * @throws \Exception
     */
    #[Route('/versions', name: 'versions', methods: ['GET'])]
    #[OA\Get(
        description: 'Method to get all versions of the element by type and ID.',
        summary: 'Get all Versions of Element (eg. Asset, Object)',
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
                            property: 'id',
                            description: 'Version ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'cid',
                            description: 'Asset ID',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'ctype',
                            description: 'Object type',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'note',
                            description: 'Version note',
                            type: 'string'
                        ),
                        new OA\Property(
                            property: 'date',
                            description: 'Timestamp of version creation',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'public',
                            description: 'Version is public?',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'versionCount',
                            description: 'Version sequence number',
                            type: 'integer'
                        ),
                        new OA\Property(
                            property: 'autoSave',
                            description: 'Version is auto-save?',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'user',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(
                                        property: 'name',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'id',
                                        type: 'integer'
                                    ),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'index',
                            type: 'integer',
                        ),
                        new OA\Property(
                            property: 'scheduled',
                            type: 'integer',
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
    public function getVersions(): Response
    {
        $type = $this->request->query->getString('type');
        $elementByIdType = $this->getElementByIdType();
        try {
            $configuration = $this->getDataHubConfiguration();
            $configReader = new ConfigReader($configuration->getConfiguration());
        } catch (\Exception $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
        if ($elementByIdType->isAllowed('versions', $this->user)) {
            $schedule = $elementByIdType->getScheduledTasks();
            $schedules = [];
            foreach ($schedule as $task) {
                if ($task->getActive()) {
                    $schedules[$task->getVersion()] = $task->getDate();
                }
            }

            // only load auto-save versions from current user
            $listing = new Listing();
            $listing->setLoadAutoSave(true);

            $listing->setCondition('cid = ? AND ctype = ? AND (autoSave=0 OR (autoSave=1 AND userId = ?)) ', [
                $elementByIdType->getId(),
                $type,
                $this->user->getId(),
            ])
                ->setOrderKey('date')
                ->setOrder('ASC');

            $versionsObject = $listing->load();
            $versionsArray = Service::getSafeVersionInfo($versionsObject);
            $versionsArray = array_reverse($versionsArray); // reverse array to sort by ID DESC
            $versions = [];
            foreach ($versionsArray as $versionArray) {
                $versions[$versionArray['id']] = $versionArray;
            }

            $versionsObject = array_reverse($versionsObject); // reverse array to sort by ID DESC
            foreach ($versionsObject as $versionObject) {
                $version = $versions[$versionObject->getId()];
                if (0 === $version['index']
                    && $version['date'] == $elementByIdType->getModificationDate()
                    && $version['versionCount'] == $elementByIdType->getVersionCount()
                ) {
                    $version['public'] = true;
                }

                $version['modificationDate'] = $version['date'];
                $version['creationDate'] = $version['date'];
                $version['scheduled'] = null;
                if ($elementByIdType instanceof Asset) {
                    $version['name'] = $elementByIdType->getFilename();
                }
                if ($elementByIdType instanceof DataObject) {
                    $version['name'] = $elementByIdType->getKey();
                }

                if (\array_key_exists($version['id'], $schedules)) {
                    $version['scheduled'] = $schedules[$version['id']];
                }

                unset($version['date']);

                $version = $this->getAssetMetaData($versionObject, $version, $configReader);
                $versions[$versionObject->getId()] = $version;
            }

            return $this->json([
                'total_count' => \count($versionsArray),
                'items' => $versions,
            ]);
        } else {
            return new JsonResponse(['success' => false, 'message' => 'Permission denied, '.$type.' id ['.$elementByIdType->getId().']']);
        }
    }

    #[Route('/lock', name: 'lock', methods: ['POST'])]
    #[OA\Post(
        description: 'Method to lock single element by type and ID.',
        summary: 'Lock Asset',
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
                            description: 'Success status.',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'message',
                            description: 'Message.',
                            type: 'string',
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
    public function lock(AssetHelper $assetHelper, IndexPersistenceService $indexPersistenceService): Response
    {
        $elementByIdType = $this->getElementByIdType();
        $elementType = $elementByIdType instanceof Asset ? 'asset' : 'object';
        if ('folder' !== $elementByIdType->getType()
            && ($elementByIdType->isAllowed('publish', $this->user)
                || $elementByIdType->isAllowed('delete', $this->user))
        ) {
            if ($assetHelper->isLocked($elementByIdType->getId(), $elementType, $this->user->getId())) {
                return new JsonResponse(['success' => false, 'message' => $elementType.' with id ['.$elementByIdType->getId().'] is already locked for editing'], 403);
            }

            $assetHelper->lock($elementByIdType->getId(), $elementType, $this->user->getId());
            try {
                $indexPersistenceService->update(
                    $elementByIdType,
                    $elementType,
                    $this->request->get('config')
                );
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }

            return new JsonResponse(['success' => true, 'message' => $elementType.' with id ['.$elementByIdType->getId().'] was just locked']);
        }

        return new JsonResponse(['success' => false, 'message' => 'Missing the permission to create new '.$elementType.' in the folder: '.$elementByIdType->getParent()->getRealFullPath()]);
    }

    #[Route('/unlock', name: 'unlock', methods: ['POST'])]
    #[OA\Post(
        description: 'Method to unlock single element by type and ID.',
        summary: 'Unlock Asset',
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
                            description: 'Success status.',
                            type: 'boolean'
                        ),
                        new OA\Property(
                            property: 'message',
                            description: 'Message.',
                            type: 'string',
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
    public function unlock(AssetHelper $assetHelper, IndexPersistenceService $indexPersistenceService): Response
    {
        $elementByIdType = $this->getElementByIdType();
        $elementType = $elementByIdType instanceof Asset ? 'asset' : 'object';
        // check for lock on non-folder items only.
        if ('folder' !== $elementByIdType->getType() && ($elementByIdType->isAllowed('publish', $this->user) || $elementByIdType->isAllowed('delete', $this->user))) {
            if ($assetHelper->isLocked($elementByIdType->getId(), $elementType, $this->user->getId())) {
                $unlocked = $assetHelper->unlockForLocker($this->user->getId(), $elementByIdType->getId());
                if ($unlocked) {
                    return new JsonResponse(['success' => true, 'message' => $elementType.' with id ['.$elementByIdType->getId().'] has been unlocked for editing']);
                }

                try {
                    $indexPersistenceService->update(
                        $elementByIdType,
                        $elementType,
                        $this->request->get('config')
                    );
                } catch (\Exception $e) {
                    Logger::crit($e->getMessage());
                }

                return new JsonResponse(['success' => true, 'message' => $elementType.' with id ['.$elementByIdType->getId().'] is locked for editing'], 403);
            }

            return new JsonResponse(['success' => false, 'message' => $elementType.' with id ['.$elementByIdType->getId().'] is already unlocked for editing']);
        }

        return new JsonResponse(['success' => false, 'message' => 'Missing the permission to create new '.$elementType.' in the folder: '.$elementByIdType->getParent()->getRealFullPath()]);
    }

    /**
     * @throws \Exception
     */
    private function getAssetMetaData(AbstractModel $model, $result, ConfigReader $configReader): array
    {
        if (($model instanceof Asset || $model instanceof Version) && !$model instanceof Asset\Folder) {
            if ($model instanceof Version) {
                $version = $model->getData();
                if ($version) {
                    $result = array_merge($result, [
                        'mimeType' => $version->getMimeType(),
                        'fileSize' => $version->getFileSize(),
                        'binaryData' => $this->getAssetProvider()->getBinaryDataValues($model, $configReader),
                        'metaData' => $this->getAssetProvider()->getMetaDataValues($version),
                    ]);
                }
            } else {
                $result = array_merge($result, [
                    'mimeType' => $model->getMimeType(),
                    'fileSize' => $model->getFileSize(),
                    'binaryData' => $this->getAssetProvider()->getBinaryDataValues($model, $configReader),
                    'metaData' => $this->getAssetProvider()->getMetaDataValues($model),
                ]);
            }
        }
        if ($model instanceof Version) {
            $model = $model->getData();
        }
        if ($model instanceof Image) {
            $result = array_merge($result, [
                'dimensionData' => [
                    'width' => $model->getWidth(),
                    'height' => $model->getHeight(),
                ],
                'xmpData' => $model->getXMPData() ?: null,
                'exifData' => $model->getEXIFData() ?: null,
                'iptcData' => $model->getIPTCData() ?: null,
            ]);
        }

        return $result;
    }
}
