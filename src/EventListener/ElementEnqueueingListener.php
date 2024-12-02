<?php

declare(strict_types=1);

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

use CIHub\Bundle\SimpleRESTAdapterBundle\Loader\CompositeConfigurationLoader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\DeleteIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\UpdateIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Pimcore\Event\AssetEvents;
use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\AssetEvent;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;
use Psr\Log\LoggerInterface;
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
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function enqueueAsset(AssetEvent $assetEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to enqueueAsset: %d',
            $assetEvent->getAsset()->getId()
        ), $assetEvent->getArguments());

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

        $element = $assetEvent->getElement();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $hash = null;
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$configReader->isAssetIndexingEnabled()) {
                continue;
            }

            $this->messageBus->dispatch(
                new UpdateIndexElementMessage($element->getId(), self::TYPE_ASSET, $endpointName, $hash, $configReader)
            );
        }
    }

    public function enqueueObject(DataObjectEvent $dataObjectEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to enqueueObject: %d',
            $dataObjectEvent->getObject()->getId()
        ), $dataObjectEvent->getArguments());

        $object = $dataObjectEvent->getObject();

        if (!$object instanceof DataObject\Concrete) {
            return;
        }

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $hash = null;
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

            $this->messageBus->dispatch(
                new UpdateIndexElementMessage($object->getId(), self::TYPE_OBJECT, $endpointName, $hash, $configReader)
            );
        }
    }

    public function removeAsset(AssetEvent $assetEvent): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to removeAsset: %d',
            $assetEvent->getAsset()->getId()
        ), $assetEvent->getArguments());
        $element = $assetEvent->getElement();

        $configurations = $this->compositeConfigurationLoader->loadConfigs();

        foreach ($configurations as $configuration) {
            $endpointName = $configuration->getName();
            $configReader = new ConfigReader($configuration->getConfiguration());

            // Check if assets are enabled
            if (!$configReader->isAssetIndexingEnabled()) {
                continue;
            }

            $indexName = $this->indexManager->getIndexName($element, $endpointName);

            $this->messageBus->dispatch(
                new DeleteIndexElementMessage($element->getId(), self::TYPE_ASSET, $endpointName, $indexName)
            );
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

            $this->messageBus->dispatch(
                new DeleteIndexElementMessage($object->getId(), self::TYPE_OBJECT, $endpointName, $indexName)
            );
        }
    }
}
