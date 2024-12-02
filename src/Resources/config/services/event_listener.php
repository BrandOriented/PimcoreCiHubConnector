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

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\EndpointAndIndexesConfigurator;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\EventListener\ConfigModificationListener;
use CIHub\Bundle\SimpleRESTAdapterBundle\EventListener\CorsListener;
use CIHub\Bundle\SimpleRESTAdapterBundle\EventListener\ElementEnqueueingListener;
use CIHub\Bundle\SimpleRESTAdapterBundle\EventListener\ExceptionListener;
use CIHub\Bundle\SimpleRESTAdapterBundle\Loader\CompositeConfigurationLoader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(ConfigModificationListener::class)
        ->args([
            service(IndexManager::class),
            service(EndpointAndIndexesConfigurator::class),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(ElementEnqueueingListener::class)
        ->args([
            service(CompositeConfigurationLoader::class),
            service(IndexManager::class),
            service(MessageBusInterface::class),
            service('logger'),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(ExceptionListener::class)
        ->args([
            service('logger'),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(CorsListener::class)
        ->args([
            service('logger'),
        ])
        ->tag('kernel.event_subscriber');
};
