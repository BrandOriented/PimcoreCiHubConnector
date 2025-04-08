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

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexQueryService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\InvalidParameterException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\NotFoundException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\AuthManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\AssetPreviewImageMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\DeleteIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Provider\AssetProvider;
use CIHub\Bundle\SimpleRESTAdapterBundle\Provider\DataObjectProvider;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use CIHub\Bundle\SimpleRESTAdapterBundle\Services\ThumbnailService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\RestHelperTrait;
use CIHub\Bundle\SimpleRESTAdapterBundle\Transformer\FilterFieldNameTransformerInterface;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Nelmio\ApiDocBundle\Annotation\Security;
use ONGR\ElasticsearchDSL\Query\FullText\MatchQuery;
use OpenApi\Attributes as OA;
use Pimcore\Logger;
use Pimcore\Messenger\AssetPreviewImageMessage as PimcoreAssetPreviewMessage;
use Pimcore\Messenger\AssetUpdateTasksMessage;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Image\Thumbnail\Config;
use Pimcore\Model\Version;
use Pimcore\Tool\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route(path: ['/datahub/rest/{config}/asset', '/pimcore-datahub-webservices/simplerest/{config}'], name: 'datahub_rest_endpoints_asset_')]
#[Security(name: 'Bearer')]
#[OA\Tag(name: 'Asset')]
class DownloadController extends BaseEndpointController
{
    use RestHelperTrait;

    public function __construct(
        DataHubConfigurationRepository $dataHubConfigurationRepository,
        LabelExtractorInterface $labelExtractor,
        RequestStack $requestStack,
        AuthManager $authManager,
        AssetProvider $assetProvider,
        DataObjectProvider $dataObjectProvider,
        FilterFieldNameTransformerInterface $filterFieldNameTransformer,
        private IndexManager $indexManager,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct(
            $dataHubConfigurationRepository,
            $labelExtractor,
            $requestStack,
            $authManager,
            $assetProvider,
            $dataObjectProvider,
            $filterFieldNameTransformer
        );
    }

    /**
     * @throws FilesystemException
     */
    #[Route('/download', name: 'download', methods: ['GET', 'OPTIONS'])]
    #[OA\Get(
        description: 'Method to download binary file by asset ID.',
        summary: 'Download Asset',
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
                name: 'id',
                description: 'ID of the element.',
                in: 'query',
                required: true,
                schema: new OA\Schema(
                    type: 'integer'
                )
            ),
            new OA\Parameter(
                name: 'version',
                description: 'Version of the element.',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'integer'
                )
            ),
            new OA\Parameter(
                name: 'type',
                description: 'Type of elements – asset, object or version.',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['asset', 'object', 'version']
                )
            ),
            new OA\Parameter(
                name: 'thumbnail',
                description: 'Thumbnail config name',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string'
                ),
                examples: [new OA\Examples('pimcore-system-treepreview', '', value: 'pimcore-system-treepreview')]
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation.',
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
    public function download(): Response
    {
        // Send empty response for OPTIONS requests
        if ($this->request->isMethod('OPTIONS')) {
            return new Response('', 204);
        }

        $configuration = null;
        try {
            // Check if request is authenticated properly
            $this->authManager->checkAuthentication();
            $configuration = $this->getDataHubConfiguration();
        } catch (\Exception $ex) {
            Logger::err($ex->getMessage());

            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        $configReader = new ConfigReader($configuration->getConfiguration());

        $id = $this->request->query->getInt('id');
        $type = $this->request->query->getString('type', 'asset');

        try {
            // Check if required parameters are missing
            $this->checkRequiredParameters(['id' => $id]);
        } catch (InvalidParameterException $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        $element = null;
        try {
            $element = $this->getElementByIdType();
        }
        catch(NotFoundException $e) {
            // Element requested, yet it was not found, try to auto-fix
            // It must have been deleted. There must have been problem with indexing removal.
            //
            // Queue removing element from index, to avoid false-positive result
            // of finding non-existent element again after handling message
            //
            // `Partial Deletions`
            // @see https://rockset.com/articles/elasticsearch-delete-index/
            //
            $endpointName = $configuration->getName();
            $indexName = $this->indexManager->getIndexName($type, $endpointName);

            $this->messageBus->dispatch(
                new DeleteIndexElementMessage($id, $type, $endpointName, $indexName)
            );

            throw $e;
        }

        if ($element instanceof Version) {
            $element = $element->getData();
        }

        if (!$element->isAllowed('view', $this->user)) {
            return new JsonResponse([
                'error' => 'Your request to view a folder has been blocked due to missing permissions',
            ]);
        }

        $thumbnailName = (string) $this->request->get('thumbnail');

        Logger::debug('CIHUB: Requested download action', [
            'thumbnailName' => $thumbnailName,
            'configReaderType' => $configReader->getType(),
            'id' => $id,
            'element::class' => get_class($element),
        ]);

        // Asset found, we do not want preview, provide original file which will
        // be used by client app
        if (empty($thumbnailName) && ($element instanceof Asset) && $configReader->isOriginalImageAllowed()) {
            Logger::debug('CIHUB: Providing original file');

            $filename = basename(rawurldecode((string) $element->getPath()));
            $filenameFallback = preg_replace("/[^\w\-\.]/", '', $filename);
            $stream = $element->getStream();
            $response = new StreamedResponse(function () use ($stream): void {
                fpassthru($stream);
            }, Response::HTTP_OK, [
                'Content-Type' => $element->getMimetype(),
                'Content-Length' => $element->getFileSize(),
                'Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, $filenameFallback),
            ]);

            return $response;
        }

        if (AssetProvider::CIHUB_PREVIEW_THUMBNAIL === $thumbnailName && 'ciHub' === $configReader->getType()) {
            //$thumbnailName = $this->getParameter('pimcore_ci_hub_adapter.default_preview_thumbnail');
            // Asset\Image\Thumbnail\Config::PREVIEW_THUMBNAIL_NAME
            $thumbnailName = 'pimcore-system-treepreview';
        }

        $thumbnailConfig = match(true) {
            $element instanceof Asset\Image     => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
            $element instanceof Asset\Document  => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
            //$element instanceof Asset\Video     => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
            $element instanceof Asset\Video     => Asset\Image\Thumbnail\Config::getPreviewConfig(),
            default => null,
        };

        if ($thumbnailConfig === null) {
            $thumbnailConfig = match(true) {
                $element instanceof Asset\Image     => Asset\Image\Thumbnail\Config::getPreviewConfig(),
                $element instanceof Asset\Document  => Asset\Image\Thumbnail\Config::getPreviewConfig(),
                $element instanceof Asset\Video     => Asset\Image\Thumbnail\Config::getPreviewConfig(),
                default => null,
            };
        }

        $noThumbnailResponse = $this->getNoThumbnailResponse();

        /** @var Asset\Document\ImageThumbnailInterface|Asset\Image\ThumanailInterface|Asset\Video\ImageThumbnailInterface|false|null $thumbnailFile */
        $thumbnailFile = null;
        try {
            $thumbnailFile = match(true) {
                $element instanceof Asset\Document  => $element->getImageThumbnail($thumbnailConfig),
                $element instanceof Asset\Image     => $element->getThumbnail($thumbnailConfig),
                $element instanceof Asset\Video     => $element->getImageThumbnail($thumbnailConfig),
                $element instanceof Asset\Archive   => false,
                $element instanceof Asset\Audio     => false,
                $element instanceof Asset\Unknown   => false,
                default => null,
            };
        }
        catch (\Exception $e) {
            Logger::error($e->getMessage(), [
                'id' => $element->getId(),
                'filename' => $element->getFilename(),
                'realpath' => $element->getRealPath(),
                'checksum' => $element->getChecksum(),
            ]);
            return $noThumbnailResponse;
        }

        if ($thumbnailFile === false) {
            Logger::debug(sprintf(
                'CIHUB: Asset %s does not support previews, responding with no thumbnail',
                get_class($element)
            ), [
                'id' => $element->getId(),
                'filename' => $element->getFilename(),
                'realpath' => $element->getRealPath(),
                'checksum' => $element->getChecksum(),
            ]);

            $this->addThumbnailCacheHeaders($noThumbnailResponse);
            return $noThumbnailResponse;
        }

        if ($thumbnailFile === null) {
            Logger::warning('CIHUB: No thumbnail file to deduce preview', [
                'id' => $element->getId(),
                'filename' => $element->getFilename(),
                'realpath' => $element->getRealPath(),
                'checksum' => $element->getChecksum(),
            ]);
            return $noThumbnailResponse;
        }

        assert($thumbnailFile instanceof Asset\Thumbnail\ThumbnailInterface);

        $pathReference = $thumbnailFile->getPathReference(true);

        Logger::debug('CIHUB: Details about thumbnail to handle', [
            'pathReference(defered)' => $pathReference,
        ]);
        // `type` key, will be always deferred, so instead of calling ::exists()
        // we explicitly call ::existsOnStorage()
        //if (!$thumbnailFile->exists()) {
        if (!$thumbnailFile->existsOnStorage($pathReference)) {
            $message = new AssetPreviewImageMessage($element->getId(), $thumbnailName);

            if (ThumbnailService::isMessageQueued($message)) {
                Logger::debug('CIHUB: No stream found yet preview generation is already queued, responding with no thumbnail');
            }
            else if (ThumbnailService::lockMessage($message)) {
                Logger::debug('CIHUB: No stream found, responding with no thumbnail and queuing preview generation');
                $bus = \Pimcore::getContainer()->get('messenger.bus.pimcore-core');
                $bus->dispatch($message);
                $bus->dispatch(new PimcoreAssetPreviewMessage($message->getId()));
            }

            return $noThumbnailResponse;
        }

        $stream = null;
        try {
            $stream = $thumbnailFile->getStream();
        }
        catch (UnableToReadFile $e) {
            Logger::err($e->getMessage(), [
                'id' => $element->getId(),
                'filename' => $element->getFilename(),
                'realpath' => $element->getRealPath(),
                'checksum' => $element->getChecksum(),
            ]);
            return $noThumbnailResponse;
        }

        $mimeType = $thumbnailFile->getMimeType();
        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
        ]);

        Logger::debug('CIHUB: preview data found for element, streaming normal preview response', [
            'id' => $element->getId(),
            'filename' => $element->getFilename(),
            'realpath' => $element->getRealPath(),
            'checksum' => $element->getChecksum(),
        ]);

        $this->addThumbnailCacheHeaders($response);
        $response->setEtag($element->getCacheTag());

        return $response;
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     */
    #[Route('/download-links', name: 'download_links', methods: ['GET'])]
    #[OA\Get(
        description: 'Method to return filtered list of links to assets.',
        summary: 'List assets',
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
                name: 'plu',
                description: 'Value from the "metaData.Default.PLU"',
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
                            property: 'total_count',
                            description: 'Total count',
                            type: 'integer',
                            example: 1
                        ),
                        new OA\Property(
                            property: 'items',
                            description: 'Asset path',
                            type: 'array',
                            items: new OA\Items(
                                type: 'string',
                                example: '/datahub/rest/{config}/asset/download?id=1'
                            )
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Bad request data'
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
    public function downloadLinks(
        IndexManager $indexManager,
        IndexQueryService $indexQueryService,
        Request $request,
        RouterInterface $router
    ): Response {
        $configName = $this->config;
        try {
            $this->authManager->checkAuthentication();
            $configuration = $this->getDataHubConfiguration();
            $configReader = new ConfigReader($configuration->getConfiguration());
        } catch (\Exception $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        $plu = $request->query->getString('plu');
        try {
            $this->checkRequiredParameters(['plu' => $plu]);
        } catch (InvalidParameterException $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        $indices = [];
        if ($configReader->isAssetIndexingEnabled()) {
            $indices = [$indexManager->getIndexName(IndexManager::INDEX_ASSET, $configName)];
        }

        $search = $indexQueryService->createSearch();
        try {
            $this->applySearchSettings($search);
        } catch (\Exception $ex) {
            return new JsonResponse([
                'success' => false,
                'message' => $ex->getMessage(),
            ]);
        }

        $search->addQuery(new MatchQuery('metaData.Default.PLU', $plu));

        $result = $indexQueryService->search(implode(',', $indices), $search->toArray());

        $hits = $result['hits'] ?? [];
        $total = $hits['total'] ?? 0;
        $entries = $hits['hits'] ?? [];

        $items = [];
        if ($total > 0) {
            $ids = array_map(fn (array $v) => $v['_id'], $entries);

            $items = array_map(fn ($id): string => $router->generate('datahub_rest_endpoints_asset_download', [
                'config' => $configName,
                'id' => $id,
            ]), $ids);
        }

        return $this->json([
            'total_count' => $total,
            'items' => $items,
        ]);
    }

    public function getStoragePath(ThumbnailInterface $thumb, int $id, string $filename, string $realPlace, string $checksum): string
    {
        Logger::debug('CIHUB: Getting storage path', [
            'id' => $id,
            'filename' => $filename,
            'realPlace' => $realPlace,
            'checksum' => $checksum,
        ]);

        $thumbnail = $thumb->getConfig();
        $format = mb_strtolower($thumbnail->getFormat());
        $fileExt = pathinfo($filename, \PATHINFO_EXTENSION);

        // simple detection for source type if SOURCE is selected
        if ('source' === $format || ('' === $format || '0' === $format)) {
            $thumbnail->setFormat('jpeg'); // default format for documents is JPEG not PNG (=too big)
            $optimizedFormat = true;
            $format = ThumbnailService::getAllowedFormat($fileExt, ['pjpeg', 'jpeg', 'gif', 'png'], 'png');
            if ('jpeg' === $format) {
                $format = 'pjpeg';
            }
        }

        $thumbDir = rtrim($realPlace, '/').'/'.$id.'/image-thumb__'.$id.'__'.$thumbnail->getName();
        $filename = preg_replace("/\.".preg_quote(pathinfo($filename, \PATHINFO_EXTENSION), '/').'$/i', '', $filename);

        // add custom suffix if available
        if ($thumbnail->getFilenameSuffix()) {
            $filename .= '~-~'.$thumbnail->getFilenameSuffix();
        }
        // add high-resolution modifier suffix to the filename
        if ($thumbnail->getHighResolution() > 1) {
            $filename .= '@'.$thumbnail->getHighResolution().'x';
        }

        $fileExtension = $format;
        if ('original' === $format) {
            $fileExtension = $fileExt;
        } elseif ('pjpeg' === $format || 'jpeg' === $format) {
            $fileExtension = 'jpg';
        }

        $filename .= '.'.$thumbnail->getHash([$checksum]).'.'.$fileExtension;

        return $thumbDir.'/'.$filename;
    }

    /**
     * @return Response
     */
    private function getNoThumbnailResponse(): Response
    {
        return new Response(
            base64_decode('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'),
            200, [
            'Content-Type' => 'image/webp'
        ]);
    }
}
