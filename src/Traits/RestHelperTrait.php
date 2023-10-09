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
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Document;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Model\Version;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait RestHelperTrait
{
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

    private function getElementByIdType(): ElementInterface
    {
        $id = $this->request->query->getInt('id');
        $type = $this->request->query->getString('type');
        if (!isset($type)) {
            throw new InvalidParameterException(['type']);
        }

        $element = match ($type) {
            'asset' => Asset::getById($id),
            'object' => DataObject::getById($id),
            default => throw new NotFoundException($type." with doesn't exist"),
        };
        if (!$element instanceof ElementInterface) {
            throw new NotFoundException($type.' with id ['.$id."] doesn't exist");
        }

        return $element;
    }

    public function getParent(): Asset|DataObject
    {
        $type = $this->request->request->getString('type');

        return match ($type) {
            'asset' => $this->getAssetParent(),
            'object' => $this->getObjectParent(),
            default => throw new NotFoundException('Type ['.$type.'] is not supported'),
        };
    }

    public function getAssetParent(): Asset
    {
        if ($this->request->query->has('parentId')) {
            $parentId = $this->request->query->getInt('parentId');
            $parentAsset = Asset::getById($parentId);
            if (!$parentAsset instanceof Asset) {
                throw new NotFoundException(sprintf('Parent [%s] does not exist', $parentId));
            }

            return $parentAsset;
        }

        throw new NotFoundException('ParentId is required');
    }

    /**
     * @throws \Exception
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

    public function createAssetFolder(Asset $asset): Asset
    {
        $name = $this->request->query->getString('name');
        $equalAsset = Asset::getByPath($asset->getRealFullPath().'/'.$name);
        if ($asset->isAllowed('create', $this->user)) {
            if (!$equalAsset instanceof Asset) {
                return Asset::create($asset->getId(), [
                    'filename' => $name,
                    'type' => 'folder',
                    'userOwner' => $this->user->getId(),
                    'userModification' => $this->user->getId(),
                ]);
            } else {
                throw new AssetExistsException('Folder with this name already exists');
            }
        }

        throw new AccessDeniedHttpException('Your request to create a folder has been blocked due to missing permissions');
    }

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
     * @throws ValidationException
     */
    public function createObjectFolder(DataObject $dataObject): DataObject
    {
        $name = $this->request->request->getString('name');
        if ($dataObject->isAllowed('create', $this->user)) {
            if (!Service::pathExists($dataObject->getRealFullPath().'/'.$name)) {
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
            } else {
                throw new AssetExistsException('Folder with this name already exists');
            }
        } else {
            throw new AccessDeniedHttpException('Your request to create a folder has been blocked due to missing permissions');
        }
    }

    /**
     * @throws \Exception
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

    public function getChild(ElementInterface $element): array
    {
        $subtype = null;
        if ($element instanceof Asset || $element instanceof Document) {
            $subtype = $element->getType();
        } elseif ($element instanceof Concrete) {
            $subtype = $element->getClassName();
        } elseif ($element instanceof DataObject\Folder) {
            $subtype = 'folder';
        }

        return [
            'system' => [
                'id' => $element->getId(),
                'key' => $element->getKey(),
                'fullPath' => $element->getFullPath(),
                'type' => $element->getType(),
                'locked' => $element->isLocked(),
                'parentId' => $element->getParentId(),
                'hasChildren' => $element->hasChildren(),
                'creationDate' => $element->getCreationDate(),
                'modificationDate' => $element->getModificationDate(),
                'subtype' => $subtype,
            ],
        ];
    }
}