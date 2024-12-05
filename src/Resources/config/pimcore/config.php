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

use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\AssetPreviewImageMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\DeleteIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\RebuildIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\RebuildUpdateIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\UpdateIndexElementMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('pimcore_admin', [
        'admin_csp_header' => [
            'exclude_paths' => [
                '@^/admin/datahub/rest/swagger@',
            ],
        ],
    ]);

    $containerConfigurator->extension('framework', [
        'messenger' => [
            'transports' => [
                'datahub_es_index_queue' => 'doctrine://default?queue_name=datahub_es_index_queue',
            ],
            'routing' => [
                RebuildIndexElementMessage::class => 'datahub_es_index_queue',
                RebuildUpdateIndexElementMessage::class => 'datahub_es_index_queue',
                UpdateIndexElementMessage::class => 'datahub_es_index_queue',
                DeleteIndexElementMessage::class => 'datahub_es_index_queue',
                AssetPreviewImageMessage::class => 'pimcore_core',
            ],
        ],
    ]);
};
