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
declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Provider\Traits;

use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Traits\LockedTrait;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Document;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\Asset\Image\Thumbnail\Config;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Version;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Webmozart\Assert\Assert;

/**
 * @TODO This trait should be refactored, especially ::getBinaryDataValues() method
 */
trait HasBaseAssetProvider
{
    use LockedTrait;

    public const CIHUB_PREVIEW_THUMBNAIL = 'galleryThumbnail';

    private array $defaultPreviewThumbnail;
    private RouterInterface $router;

    /**
     * @throws \Exception
     */
    public function getIndexData(ElementInterface $element, ConfigReader $configReader): array
    {
        /* @var Asset $element */
        Assert::isInstanceOf($element, Asset::class);

        $data = [
            'system' => $this->getSystemValues($element),
        ];

        if (!$element instanceof Folder) {
            $data = array_merge($data, array_filter([
                'binaryData' => $this->getBinaryDataValues($element, $configReader),
                'metaData' => $this->getMetaDataValues($element),
            ]));
        }

        if ($element instanceof Image) {
            $data = array_merge($data, array_filter([
                'dimensionData' => [
                    'width' => $element->getWidth(),
                    'height' => $element->getHeight(),
                ],
//                'xmpData' => $element->getXMPData() ?: null,
                'exifData' => $element->getEXIFData() ?: null,
                'iptcData' => $element->getIPTCData() ?: null,
            ]));
        }

        return $data;
    }

    /**
     * Returns the binary data values of an asset.
     *
     * @return array<string, array>
     *
     * @throws \Exception
     */
    public function getBinaryDataValues(Asset|Version $asset, ConfigReader $configReader): array
    {
        $data = [];

        $object = $asset instanceof Version ? $asset->getData() : $asset;
        $id = $object->getId();
        try {
            $checksum = $this->getChecksum($object);
        } catch (\Exception) {
            $checksum = null;
        }

        if ($configReader->isOriginalImageAllowed()) {
            $data['original'] = [
                'checksum' => $checksum,
                'filename' => $object->getFilename(),
            ];
            if ($asset instanceof Version) {
                $data['original']['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                    'config' => $configReader->getName(),
                    'id' => $asset->getId(),
                    'type' => 'version',
                ], UrlGeneratorInterface::ABSOLUTE_PATH);
            } else {
                $data['original']['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                    'config' => $configReader->getName(),
                    'id' => $id,
                ], UrlGeneratorInterface::ABSOLUTE_PATH);
            }
        }

        if ($object instanceof Image) {
            $thumbnails = $configReader->getAssetThumbnails();

            foreach ($thumbnails as $thumbnailName) {
                $thumbnail = $object->getThumbnail($thumbnailName);

                try {
                    $thumbChecksum = $this->getChecksum($thumbnail->getAsset());
                } catch (\Exception) {
                    $thumbChecksum = null;
                }

                $data[$thumbnailName] = [
                    'checksum' => $thumbChecksum,
                    'filename' => $thumbnail->getAsset()->getFilename(),
                ];

                if ($asset instanceof Version) {
                    $data[$thumbnailName]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $asset->getId(),
                        'type' => 'version',
                        'thumbnail' => $thumbnailName,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                } else {
                    $data[$thumbnailName]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $id,
                        'thumbnail' => $thumbnailName,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                }
            }

            // Make sure the preview thumbnail used by CI HUB is added to the list of thumbnails
            if (!\array_key_exists(self::CIHUB_PREVIEW_THUMBNAIL, $data) && 'ciHub' === $configReader->getType()) {
                if (Config::getByName(self::CIHUB_PREVIEW_THUMBNAIL) instanceof Config) {
                    $thumbnail = $object->getThumbnail(self::CIHUB_PREVIEW_THUMBNAIL);
                } else {
                    $thumbnail = $object->getThumbnail($this->defaultPreviewThumbnail);
                }

                try {
                    $thumbChecksum = $this->getChecksum($thumbnail->getAsset());
                } catch (\Exception) {
                    $thumbChecksum = null;
                }

                $data[self::CIHUB_PREVIEW_THUMBNAIL] = [
                    'checksum' => $thumbChecksum,
                    'filename' => $thumbnail->getAsset()->getKey(), // pathinfo($thumbnail->get(), PATHINFO_BASENAME),
                ];
                if ($asset instanceof Version) {
                    $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $asset->getId(),
                        'type' => 'version',
                        'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                } else {
                    $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $id,
                        'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                }
            }
        } else {
            // Add the preview thumbnail for CI HUB
            if ($object instanceof Document && 'ciHub' === $configReader->getType()) {
                if (Config::getByName(self::CIHUB_PREVIEW_THUMBNAIL) instanceof Config) {
                    $thumbnail = $object->getImageThumbnail(self::CIHUB_PREVIEW_THUMBNAIL);
                } else {
                    $thumbnail = $object->getImageThumbnail($this->defaultPreviewThumbnail);
                }

                try {
                    $thumbChecksum = $this->getChecksum($thumbnail->getAsset());
                } catch (\Exception) {
                    $thumbChecksum = null;
                }

                $data[self::CIHUB_PREVIEW_THUMBNAIL] = [
                    'checksum' => $thumbChecksum,
                    'filename' => $thumbnail->getAsset()->getFilename(), // pathinfo($thumbnail->getFileSystemPath(), PATHINFO_BASENAME),
                ];

                if ($asset instanceof Version) {
                    $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $asset->getId(),
                        'type' => 'version',
                        'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                } else {
                    $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                        'config' => $configReader->getName(),
                        'id' => $id,
                        'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                    ], UrlGeneratorInterface::ABSOLUTE_PATH);
                }
            }

            if ($object instanceof Asset\Video && 'ciHub' === $configReader->getType()) {
                if (Config::getByName(self::CIHUB_PREVIEW_THUMBNAIL) instanceof Config) {
                    $thumbnail = $object->getImageThumbnail(self::CIHUB_PREVIEW_THUMBNAIL);
                } else {
                    $thumbnail = $object->getImageThumbnail($this->defaultPreviewThumbnail);
                }

                $pathReference = [];
                try {
                    $pathReference = $thumbnail->getPathReference(true);
                }
                catch(\Throwable $e) {
                    $pathReference['type'] = 'error';
                }

                if ($pathReference['type'] !== 'error') {
                    // not error, calling ::getAsset, will not throw exception
                    try {
                        $thumbChecksum = $this->getChecksum($thumbnail->getAsset());
                    } catch (\Exception) {
                        $thumbChecksum = null;
                    }

                    $data[self::CIHUB_PREVIEW_THUMBNAIL] = [
                        'checksum' => $thumbChecksum,
                        'filename' => $thumbnail->getAsset()->getFilename(), // pathinfo($thumbnail->getFileSystemPath(), PATHINFO_BASENAME),
                    ];

                    if ($asset instanceof Version) {
                        $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                            'config' => $configReader->getName(),
                            'id' => $asset->getId(),
                            'type' => 'version',
                            'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                        ], UrlGeneratorInterface::ABSOLUTE_PATH);
                    } else {
                        $data[self::CIHUB_PREVIEW_THUMBNAIL]['path'] = $this->router->generate('datahub_rest_endpoints_asset_download', [
                            'config' => $configReader->getName(),
                            'id' => $id,
                            'thumbnail' => self::CIHUB_PREVIEW_THUMBNAIL,
                        ], UrlGeneratorInterface::ABSOLUTE_PATH);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * @throws \Exception
     */
    public function getChecksum(Asset $asset, string $type = 'md5'): ?string
    {
        if ('md5' === $type) {
            return md5((string)$asset->getId());
        } elseif ('sha1' === $type) {
            return sha1((string)$asset->getId());
        } else {
            return null;
        }
    }

    /**
     * Returns the meta data values of an asset.
     *
     * @return array<string, string>|null
     */
    public function getMetaDataValues(Asset $asset): ?array
    {
        $data = null;
        $metaData = $asset->getMetadata();
        foreach ($metaData as $metumData) {
            $data[$metumData['name']] = $metumData['data'];
            $nameArray = explode('.', (string) $metumData['name']);
            $data[end($nameArray)] = $metumData['data'];
        }

        return $data;
    }

    /**
     * Returns the system values of an asset.
     *
     * @return array<string, mixed>
     */
    private function getSystemValues(ElementInterface $element): array
    {
        $type = 'object';
        $subType = 'object';
        if ($element instanceof Document) {
            $type = 'document';
            $subType = $element->getType();
        }
        if ($element instanceof Asset) {
            $type = 'asset';
            $subType = $element->getType();
        }

        $currentVersion = null;
        $currentVersionObject = $element->getVersions();
        if ([] !== $currentVersionObject && end($currentVersionObject) instanceof Version) {
            $currentVersion = end($currentVersionObject)->getId();
        }
        $data = [
            'id' => $element->getId(),
            'key' => $element->getKey(),
            'fullPath' => $element->getFullPath(),
            'parentId' => $element->getParentId(),
            'type' => 'asset',
            'subtype' => $element->getType(),
            'hasChildren' => $element->hasChildren(),
            'creationDate' => $element->getCreationDate(),
            'modificationDate' => $element->getModificationDate(),
            'locked' => $this->isLocked($element->getId(), $type),
        ];

        if (!$element instanceof Folder) {
            $data = array_merge($data, [
                'versionCount' => $element->getVersionCount(),
                'currentVersion' => $currentVersion,
            ]);
            if ($element instanceof Asset) {
                try {
                    $checksum = $this->getChecksum($element);
                } catch (\Exception) {
                    $checksum = null;
                }

                $data = array_merge($data, [
                    'checksum' => $checksum,
                    'mimeType' => $element->getMimetype(),
                    'fileSize' => $element->getFileSize(),
                ]);
            }
        }

        return $data;
    }
}
