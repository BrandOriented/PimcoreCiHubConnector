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
use CIHub\Bundle\SimpleRESTAdapterBundle\Helper\UploadHelper;
use League\Flysystem\FilesystemException;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: ['/datahub/rest/{config}/upload', '/pimcore-datahub-webservices/simplerest/{config}/asset'], name: 'datahub_rest_endpoints_upload_')]
#[Security(name: 'Bearer')]
final class UploadController extends BaseEndpointController
{
    public const PART_SIZE = 1024 * 1024;

    /**
     * @throws \Exception
     */
    #[OA\Post(
        description: 'Creates an upload session for a new or existing asset.',
        summary: 'Create an upload session for a new or existing asset',
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
                name: 'file_name',
                description: 'The name of new file',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: 'Newfile.pdf'
            ),
            new OA\Parameter(
                name: 'filesize',
                description: 'The size of new file',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
                ),
                example: '104857600'
            ),
            new OA\Parameter(
                name: 'parentId',
                description: 'Parent ID of asset.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
                ),
                example: '0'
            ),
            new OA\Parameter(
                name: 'asset_id',
                description: 'Asset ID of asset. When entered, this function updates an existing asset.',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'integer'
                ),
                example: '0'
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Returns a new upload session.'
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/start', name: 'upload_start', methods: ['POST'])]
    public function start(UploadHelper $uploadHelper): Response
    {
        $this->request->get('filesize');
        $datahubUploadSession = $uploadHelper->createSession($this->request, self::PART_SIZE);

        return new JsonResponse($uploadHelper->getSessionResponse(
            $this->request,
            $datahubUploadSession->getId(),
            $this->config,
            self::PART_SIZE,
            0,
            $datahubUploadSession->getTotalParts()
        ));
    }

    #[Route('/start', name: 'upload_start_options', methods: ['OPTIONS'])]
    public function startOptions(): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[OA\Get(
        description: 'Return information about an upload session.',
        summary: 'Get upload session',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Returns an upload session object.'
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/start', name: 'upload_start_get', methods: ['GET'])]
    public function startGet(UploadHelper $uploadHelper): Response
    {
        $id = $this->request->get('id');
        try {
            $this->checkRequiredParameters(['id' => $id]);
        } catch (InvalidParameterException $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }
        if ($uploadHelper->hasSession($id)) {
            $session = $uploadHelper->getSession($id);
            $response = $uploadHelper->getSessionResponse(
                $this->request,
                $id,
                $this->config,
                self::PART_SIZE,
                $session->getTotalParts(),
                $session->getPartsCount()
            );

            return new JsonResponse($response);
        }

        return new JsonResponse([], 404);
    }

    #[OA\Delete(
        description: 'Abort an upload session and discard all data uploaded.',
        summary: 'Remove upload session',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'A blank response is returned if the session was successfully aborted.'
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/{id}', name: 'upload_abort', methods: ['DELETE'])]
    public function abort(string $id, UploadHelper $uploadHelper): Response
    {
        $uploadHelper->deleteSession($id);

        return new JsonResponse([], 204);
    }

    #[Route('/{id}/commit', name: 'upload_commit_options', methods: ['OPTIONS'])]
    public function commitOptions(): Response
    {
        return new JsonResponse([]);
    }

    /**
     * @throws FilesystemException
     */
    #[OA\Post(
        description: 'Close an upload session and create a file from the uploaded chunks.',
        summary: 'Commit upload session',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
            new OA\Parameter(
                name: 'parts',
                description: 'The list details for the uploaded parts',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'array',
                    items: new OA\Items()
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Returns a new upload session.'
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/{id}/commit', name: 'upload_commit', methods: ['POST'])]
    public function commit(string $id, UploadHelper $uploadHelper): Response
    {
        return new JsonResponse($uploadHelper->commitSession($id));
    }

    #[OA\Get(
        description: 'Return a list of the chunks uploaded to the upload session so far.',
        summary: 'List parts',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Returns a new upload session.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'entries',
                            description: 'Parts array',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(
                                        property: 'id',
                                        description: 'Part ID',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'checksum',
                                        description: 'Checksum of the part',
                                        type: 'string'
                                    ),
                                    new OA\Property(
                                        property: 'size',
                                        description: 'Size of the part',
                                        type: 'integer'
                                    ),
                                    new OA\Property(
                                        property: 'ordinal',
                                        description: 'Ordinal number of the part',
                                        type: 'integer'
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(
                            property: 'total',
                            description: 'Total uploaded parts',
                            type: 'integer'
                        ),
                    ],
                    type: 'object',
                    example: [
                        'entries' => [
                            [
                                'id' => '01HAPEWC2QAD29AMJC9RM17CAH',
                                'checksum' => '672dbdbcf8a83ebdf9225ef6f920bb0b5b3bc7fa8f73078e3a1d0',
                                'size' => 4_857_600,
                                'ordinal' => 1,
                            ],
                            [
                                'id' => '01HAPEWC2QAD29AMJC9RM1723A',
                                'checksum' => '6eb3746e6273a5c4e656bef1536e6cec36efe53fa1d010d548942',
                                'size' => 7_857_600,
                                'ordinal' => 2,
                            ],
                        ],
                        'total' => 2,
                    ]
                )
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/{id}/parts', name: 'upload_list_parts', methods: ['GET'])]
    public function parts(string $id, UploadHelper $uploadHelper): Response
    {
        $datahubUploadSession = $uploadHelper->getSession($id);

        return new JsonResponse([
            'entries' => $datahubUploadSession->getParts()->toArray(),
            'total' => $datahubUploadSession->getPartsCount(),
        ]);
    }

    #[Route('/{id}/part', name: 'upload_part_options', methods: ['OPTIONS'])]
    public function partOptions(): JsonResponse
    {
        return new JsonResponse([]);
    }

    /**
     * @throws FilesystemException
     */
    #[OA\Put(
        description: 'Return the status of the upload.',
        summary: 'Upload part of file',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
            new OA\Parameter(
                name: 'ordinal',
                description: 'The ordinal number of the upload part.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
                ),
                example: '1'
            ),
            new OA\RequestBody(
                description: 'The binary content of the file part.',
                content: new OA\MediaType(
                    mediaType: 'application/octet-stream',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: ''
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/{id}/part', name: 'upload_part', methods: ['PUT'])]
    public function part(string $id, UploadHelper $uploadHelper): Response
    {
        $datahubUploadSession = $uploadHelper->getSession($id);

        /**
         * @var resource $content
         */
        $content = $this->request->getContent(true);
        $size = (int) $this->request->headers->get('Content-Length', 0);
        $ordinal = (int) $this->request->get('ordinal');
        if (0 === $size) {
            return new JsonResponse(['success' => false, 'message' => 'InvalidParameter Content-Length']);
        }

        if (0 === $ordinal) {
            return new JsonResponse(['success' => false, 'message' => 'InvalidParameter ordinal']);
        }

        $part = $uploadHelper->uploadPart($datahubUploadSession, $content, $size, $ordinal);

        return new JsonResponse([
            'part' => [
                'part_id' => $part->getId(),
                'checksum' => $part->getHash(),
                'size' => $part->getSize(),
            ],
        ]);
    }

    #[OA\Get(
        description: 'Return the status of the upload.',
        summary: 'Get upload status',
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
                ),
                example: 'cihub'
            ),
            new OA\Parameter(
                name: 'id',
                description: 'The ID of the upload session.',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string'
                ),
                example: '01HAPEWC2QAD29AMJC9RM17CAH'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: ''
            ),
        ],
    )]
    #[OA\Tag(name: 'Uploads (Chunked)')]
    #[Route('/{id}/status', name: 'upload_status', methods: ['GET'])]
    public function status(string $id, UploadHelper $uploadHelper): Response
    {
        if ($uploadHelper->hasSession($id)) {
            $session = $uploadHelper->getSession($id);
        }

        $response = $uploadHelper->getSessionResponse($this->request, $id, $this->config, self::PART_SIZE, $session->getPartsCount(), $session->getTotalParts());
        $response['file_size'] = $session->getFileSize();

        return new JsonResponse($response);
    }
}
