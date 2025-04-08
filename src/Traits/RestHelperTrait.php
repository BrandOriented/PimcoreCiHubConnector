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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Traits;

use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\AssetExistsException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\FolderLockedException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\InvalidParameterException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\NotFoundException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Element\DuplicateFullPathException;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Model\Version;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait RestHelperTrait
{
    /**
     * @throws NotFoundException
     */
    public function getVersion(): array
    {
        $id = $this->request->query->getInt('id');
        $type = $this->request->query->getString('type');
        $this->checkRequiredParameters(['id' => $id, 'type' => $type]);

        $version = Version::getById($id);
        if (!$version instanceof Version) {
            throw new NotFoundException('Version with id ['.$id."] doesn't exist");
        }

        $element = $version->loadData();
        if (!$element instanceof ElementInterface) {
            throw new NotFoundException($type.' with id ['.$id."] doesn't exist");
        }

        return [$element, $version];
    }

    /**
     * @throws InvalidParameterException
     * @throws NotFoundException
     */
    private function getElementByIdType(): ElementInterface|Version
    {
        $id = $this->request->query->getInt('id');
        $type = $this->request->query->getString('type', 'asset');
        if (!isset($type)) {
            throw new InvalidParameterException(['type']);
        }

        $element = match ($type) {
            'asset' => Asset::getById($id),
            'object' => DataObject::getById($id),
            'version' => Version::getById($id),
            default => throw new NotFoundException($type.' with id ['.$id."] doesn't exist"),
        };

        if (!$element instanceof ElementInterface && !$element instanceof Version) {
            throw new NotFoundException($type.' with id ['.$id."] doesn't exist");
        }

        return $element;
    }

    /**
     * @throws NotFoundException
     */
    public function getParent(): Asset|DataObject
    {
        if ($this->request->request->has('type')) {
            $type = $this->request->request->getString('type');
        } else {
            $type = $this->request->query->getString('type');
        }

        return match ($type) {
            'asset' => $this->getAssetParent(),
            'object' => $this->getObjectParent(),
            default => throw new NotFoundException('Type ['.$type.'] is not supported'),
        };
    }

    /**
     * @throws NotFoundException
     */
    public function getAssetParent(): Asset
    {
        if ($this->request->query->has('parentId')) {
            $parentId = $this->request->query->getInt('parentId');
        } elseif ($this->request->request->has('parentId')) {
            $parentId = $this->request->request->getInt('parentId');
        } else {
            throw new NotFoundException('ParentId is required');
        }
        $parentAsset = Asset::getById($parentId);
        if (!$parentAsset instanceof Asset) {
            throw new NotFoundException(sprintf('Parent [%s] does not exist', $parentId));
        }

        return $parentAsset;
    }

    /**
     * @throws FolderLockedException
     * @throws AccessDeniedHttpException
     */
    public function deleteAssetFolder(Folder $folder): bool
    {
        if ($folder->isAllowed('delete', $this->user)) {
            if ($folder->isLocked()) {
                throw new FolderLockedException(sprintf('Folder [%s] is locked', $folder->getId()));
            }

            $folder->delete();

            return true;
        }

        throw new AccessDeniedHttpException('Your request to delete a folder has been blocked due to missing permissions');
    }

    /**
     * @throws AssetExistsException
     */
    public function getElementNameFromRequest(): string
    {
        if ($this->request->query->has('name')) {
            $name = $this->request->query->getString('name');
        } elseif ($this->request->request->has('name')) {
            $name = $this->request->request->getString('name');
        } else {
            throw new AssetExistsException('Name is required');
        }

        return $name;
    }

    /**
     * @throws AccessDeniedHttpException
     * @throws AssetExistsException
     */
    public function createAssetFolder(Asset $asset): Asset
    {
        $name = $this->getElementNameFromRequest();
        $equalAsset = Asset::getByPath($asset->getRealFullPath().'/'.$name);
        if ($asset->isAllowed('create', $this->user)) {
            if (!$equalAsset instanceof Asset) {
                try {
                    return Asset::create($asset->getId(), [
                        'filename' => $name,
                        'type' => 'folder',
                        'userOwner' => $this->user->getId(),
                        'userModification' => $this->user->getId(),
                    ]);
                } catch (DuplicateFullPathException) {
                    throw new AssetExistsException('Folder with this name already exists');
                } catch (\Exception $exception) {
                    throw new AssetExistsException($exception->getMessage());
                }
            } else {
                throw new AssetExistsException('Folder with this name already exists');
            }
        }

        throw new AccessDeniedHttpException('Your request to create a folder has been blocked due to missing permissions');
    }

    /**
     * @throws NotFoundException
     */
    public function getObjectParent(): DataObject
    {
        if ($this->request->query->has('parentId')) {
            $parentId = $this->request->query->getInt('parentId');
            $parentAsset = DataObject::getById($parentId);
            if (!$parentAsset instanceof DataObject) {
                throw new NotFoundException(sprintf('Parent [%s] does not exist', $parentId));
            }

            return $parentAsset;
        }

        throw new NotFoundException('ParentId is required');
    }

    /**
     * @throws AssetExistsException
     * @throws AccessDeniedHttpException
     */
    public function createObjectFolder(DataObject $dataObject): DataObject
    {
        $name = $this->request->request->getString('name');
        if ($dataObject->isAllowed('create', $this->user)) {
            if (!Service::pathExists($dataObject->getRealFullPath().'/'.$name)) {
                try {
                    $folder = DataObject\Folder::create([
                        'parentId' => $dataObject->getId(),
                        'creationDate' => time(),
                        'userOwner' => $this->user->getId(),
                        'userModification' => $this->user->getId(),
                        'key' => $name,
                        'published' => true,
                    ]);
                    $folder->save();

                    return $folder;
                } catch (DuplicateFullPathException) {
                    throw new AssetExistsException('Folder with this name already exists');
                } catch (\Exception $exception) {
                    throw new AssetExistsException($exception->getMessage());
                }
            } else {
                throw new AssetExistsException('Folder with this name already exists');
            }
        } else {
            throw new AccessDeniedHttpException('Your request to create a folder has been blocked due to missing permissions');
        }
    }

    /**
     * @throws AccessDeniedHttpException
     * @throws FolderLockedException
     */
    public function deleteObjectFolder(DataObject\Folder $folder): bool
    {
        if ($folder->isAllowed('delete', $this->user)) {
            if ($folder->isLocked()) {
                throw new FolderLockedException(sprintf('Folder [%s] is locked', $folder->getId()));
            }

            $folder->delete();

            return true;
        }

        throw new AccessDeniedHttpException('Your request to delete a folder has been blocked due to missing permissions');
    }

    /**
     * @throws InvalidParameterException
     */
    protected function checkRequiredParameters(array $params): void
    {
        $required = [];

        foreach ($params as $key => $value) {
            if (!empty($value)) {
                continue;
            }

            $required[] = $key;
        }

        if ([] !== $required) {
            throw new InvalidParameterException($required);
        }
    }

    /**
     * @throws \InvalidParameterException
     */
    public function getChild(ElementInterface $element, ConfigReader $configReader): array
    {
        if ($element instanceof AbstractObject) {
            return $this->getDataObjectProvider()->getIndexData($element, $configReader);
        } elseif ($element instanceof Asset) {
            return $this->getAssetProvider()->getIndexData($element, $configReader);
        } else {
            throw new \InvalidArgumentException('This element type is currently not supported.');
        }
    }

    /**
     * @throws \Exception
     */
    protected function addThumbnailCacheHeaders(Response $response): void
    {
        $lifetime = 300;
        $dateTime = new \DateTime('now');
        $dateTime->add(new \DateInterval('PT'.$lifetime.'S'));

        $response->setMaxAge($lifetime);
        $response->setPublic();
        $response->setExpires($dateTime);
        $response->headers->set('Pragma', '');
        $response->setCache([
            'must_revalidate'  => true,
        ]);
    }
}
