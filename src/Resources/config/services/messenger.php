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

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler\AssetPreviewImageMessageHandler;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler\DeleteIndexElementMessageHandler;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler\RebuildIndexElementMessageHandler;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler\RebuildUpdateIndexElementMessageHandler;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler\UpdateIndexElementMessageHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();
    $services->set(RebuildIndexElementMessageHandler::class)
        ->args([
            service(MessageBusInterface::class),
            service(IndexManager::class),
            service(IndexPersistenceService::class),
        ])
        ->tag('messenger.message_handler');

    $services->set(RebuildUpdateIndexElementMessageHandler::class)
        ->args([
            service(IndexManager::class),
            service(IndexPersistenceService::class),
        ])
        ->tag('messenger.message_handler');

    $services->set(DeleteIndexElementMessageHandler::class)
        ->args([
            service(IndexPersistenceService::class),
            service('logger'),
        ])
        ->tag('messenger.message_handler');

    $services->set(UpdateIndexElementMessageHandler::class)
        ->args([
            service(IndexManager::class),
            service(IndexPersistenceService::class),
            service('logger'),
        ])
        ->tag('messenger.message_handler');

    $services->set(AssetPreviewImageMessageHandler::class)
        ->args([
            service('logger'),
        ])
        ->tag('messenger.message_handler');
};
