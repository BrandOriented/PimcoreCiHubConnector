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
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ElementEnqueueingListener implements EventSubscriberInterface
{
    public const TYPE_ASSET = 'asset';
    public const TYPE_OBJECT = 'object';

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

    public function __construct(
        private CompositeConfigurationLoader $compositeConfigurationLoader,
        private IndexManager $indexManager,
        private IndexPersistenceService $indexPersistenceService
    ) {
    }

    public function enqueueAsset(AssetEvent $assetEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to enqueueAsset: %d',
            $assetEvent->getAsset()->getId()
        ), $assetEvent->getArguments());

        //do not update index when auto save or only saving version
        /**
         * @TODO - add this as a part of every update methods
         */
        if (
            ($assetEvent->hasArgument('isAutoSave') && $assetEvent->getArgument('isAutoSave')) ||
            ($assetEvent->hasArgument('saveVersionOnly') && $assetEvent->getArgument('saveVersionOnly'))
        ) {
            $this->logger->debug(sprintf(
                'Skipping CIHub for asset: %d',
                $assetEvent->getAsset()->getId()
            ), $assetEvent->getArguments());
            return;
        }

        $asset = $assetEvent->getAsset();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        /**
         * @TODO - remove loop
         * @TODO - move all of it to queue
         */
        foreach ($configurations as $configuration) {
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$configReader->isAssetIndexingEnabled()) {
                continue;
            }

            $indexName = $this->indexManager->getIndexName($asset, $endpointName);

            try {
                $this->indexPersistenceService->update($asset, $endpointName, $indexName);
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }
            $this->enqueueParentFolders($asset->getParent(), Asset\Folder::class, $endpointName);
        }
    }

    public function enqueueObject(DataObjectEvent $dataObjectEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to enqueueObject: %d',
            $dataObjectEvent->getObject()->getId()
        ), $dataObjectEvent->getArguments());

        $object = $dataObjectEvent->getObject();

        if (!$object instanceof Concrete) {
            return;
        }

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if objects are enabled
            if (!$configReader->isObjectIndexingEnabled()) {
                continue;
            }

            // Check if object class is configured
            if (!\in_array($object->getClassName(), $configReader->getObjectClassNames(), true)) {
                continue;
            }

            $indexName = $this->indexManager->getIndexName($object, $endpointName);

            try {
                $this->indexPersistenceService->update($object, $endpointName, $indexName);
            } catch (\Exception $e) {
                Logger::crit($e->getMessage());
            }

            // Index all folders above the object
            $this->enqueueParentFolders($object->getParent(), DataObject\Folder::class, $name);
        }
    }

    public function removeAsset(AssetEvent $assetEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to removeAsset: %d',
            $assetEvent->getAsset()->getId()
        ), $assetEvent->getArguments());

        $asset = $assetEvent->getAsset();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$configReader->isAssetIndexingEnabled()) {
                continue;
            }

            $indexName = $this->indexManager->getIndexName($asset, $endpointName);

            try {
                $this->indexPersistenceService->delete($asset->getId(), $indexName);
            } catch (ClientResponseException|MissingParameterException|ServerResponseException $e) {
                Logger::crit($e->getMessage());
            }
        }
    }

    public function removeObject(DataObjectEvent $dataObjectEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to removeObject: %d',
            $dataObjectEvent->getObject()->getId()
        ), $dataObjectEvent->getArguments());

        $object = $dataObjectEvent->getObject();

        if (!$object instanceof DataObject\Concrete) {
            return;
        }

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if object class is configured
            if (!\in_array($object->getClassName(), $configReader->getObjectClassNames(), true)) {
                continue;
            }

            $indexName = $this->indexManager->getIndexName($object, $endpointName);

            try {
                $this->indexPersistenceService->delete($object->getId(), $indexName);
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
