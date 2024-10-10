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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\EventListener;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Loader\CompositeConfigurationLoader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Logger;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ElementEnqueueingListener implements EventSubscriberInterface
{
    public function __construct(
        private CompositeConfigurationLoader $compositeConfigurationLoader,
        private IndexManager $indexManager,
        private IndexPersistenceService $indexPersistenceService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AssetEvents::POST_ADD => 'enqueueAsset',
            AssetEvents::POST_UPDATE => 'enqueueAsset',
            AssetEvents::PRE_DELETE => 'removeAsset',
            DataObjectEvents::POST_ADD => 'enqueueObject',
            DataObjectEvents::POST_UPDATE => 'enqueueObject',
            DataObjectEvents::PRE_DELETE => 'removeObject',
        ];
    }

    public function enqueueAsset(AssetEvent $assetEvent): void
    {
        //do not update index when auto save or only saving version
        /**
         * @TODO - add this as a part of every update methods
         */
        if (
            ($assetEvent->hasArgument('isAutoSave') && $assetEvent->getArgument('isAutoSave')) ||
            ($assetEvent->hasArgument('saveVersionOnly') && $assetEvent->getArgument('saveVersionOnly'))
        ) {
            return;
        }

        $asset = $assetEvent->getAsset();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        /**
         * @TODO - remove loop
         * @TODO - move all of it to queue
         */
        foreach ($configurations as $configuration) {
            $name = $configuration->getName();
            $reader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$reader->isAssetIndexingEnabled()) {
                continue;
            }
            try {
                $this->indexPersistenceService->update(
                    $asset,
                    $name,
                    $this->indexManager->getIndexName($asset, $name)
                );
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }
            $this->enqueueParentFolders($asset->getParent(), Folder::class, $name);
        }
    }

    public function enqueueObject(DataObjectEvent $dataObjectEvent): void
    {
        $object = $dataObjectEvent->getObject();

        if (!$object instanceof Concrete) {
            return;
        }

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $name = $configuration->getName();
            $reader = new ConfigReader($configuration->getConfiguration());
            $objectClassNames = $reader->getObjectClassNames();

            // Check if object class is configured
            if (!\in_array($object->getClassName(), $objectClassNames, true)) {
                continue;
            }

            try {
                $this->indexPersistenceService->update(
                    $object,
                    $name,
                    $this->indexManager->getIndexName($object, $name)
                );
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }

            // Index all folders above the object
            $this->enqueueParentFolders($object->getParent(), DataObject\Folder::class, $name);
        }
    }

    public function removeAsset(AssetEvent $assetEvent): void
    {
        $asset = $assetEvent->getAsset();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $name = $configuration->getName();
            $reader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$reader->isAssetIndexingEnabled()) {
                continue;
            }

            try {
                $this->indexPersistenceService->delete($asset->getId(), $this->indexManager->getIndexName($asset, $name));
            } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
                Logger::crit($e->getMessage());
            }
        }
    }

    public function removeObject(DataObjectEvent $dataObjectEvent): void
    {
        $object = $dataObjectEvent->getObject();

        if (!$object instanceof Concrete) {
            return;
        }

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $name = $configuration->getName();
            $reader = new ConfigReader($configuration->getConfiguration());
            $objectClassNames = $reader->getObjectClassNames();

            // Check if object class is configured
            if (!\in_array($object->getClassName(), $objectClassNames, true)) {
                continue;
            }

            try {
                $this->indexPersistenceService->delete($object->getId(), $this->indexManager->getIndexName($object, $name));
            } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
                Logger::crit($e->getMessage());
            }
        }
    }

    private function enqueueParentFolders(
        ?ElementInterface $element,
        string $folderClass,
        string $name
    ): void {
        while ($element instanceof $folderClass && 1 !== $element->getId()) {
            try {
                $this->indexPersistenceService->update(
                    $element,
                    $name,
                    $this->indexManager->getIndexName($element, $name)
                );
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }
            $element = $element->getParent();
        }
    }
}
