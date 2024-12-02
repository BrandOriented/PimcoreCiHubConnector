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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Installer;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\RebuildUpdateIndexElementMessage;
use Doctrine\DBAL\Connection;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Tool\SettingsStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildUpdateIndexElementMessageHandler
{
    public function __construct(private IndexManager $indexManager, private IndexPersistenceService $indexPersistenceService)
    {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(RebuildUpdateIndexElementMessage $updateIndexElementMessage): void
    {
        if (
            $updateIndexElementMessage->getHash() &&
            SettingsStore::get(Installer::RUN_HASH, Installer::REBUILD_SCOPE)->getData() != $updateIndexElementMessage->getHash()
        ) {
            return;
        }

        $element = match ($updateIndexElementMessage->getEntityType()) {
            'asset' => Asset::getById($updateIndexElementMessage->getEntityId()),
            'object' => AbstractObject::getById($updateIndexElementMessage->getEntityId()),
            default => null,
        };

        if (!$element instanceof ElementInterface) {
            return;
        }

        $alias = $this->indexManager->getIndexName(
            $element,
            $updateIndexElementMessage->getEndpointName(),
        );
        if (!$this->indexPersistenceService->aliasExists($alias)) {
            $this->incrementProgress($updateIndexElementMessage);
            return;
        }

        $index = $this->indexManager->findIndexNameByAlias($alias);
        $newIndexName = $this->getNewIndexName($index);

        $this->indexPersistenceService->update(
            $element,
            $updateIndexElementMessage->getEndpointName(),
            $newIndexName,
        );

        if ($updateIndexElementMessage->getHash()) {
            $this->incrementProgress($updateIndexElementMessage);
        }
    }

    protected function getDb(): Connection
    {
        return Db::get();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Exception
     */
    public function incrementProgress(RebuildUpdateIndexElementMessage $updateIndexElementMessage): void
    {
        $doneCountId = Installer::RUN_DONE_COUNT;
        $scopeId = Installer::REBUILD_SCOPE;
        $this->getDb()
            ->executeQuery("UPDATE settings_store SET data = data + 1 WHERE id = '$doneCountId' AND scope = '$scopeId';")
            ->fetchAllAssociative()
        ;

        $todo = SettingsStore::get(Installer::RUN_TODO_COUNT, Installer::REBUILD_SCOPE)->getData();
        $done = SettingsStore::get(Installer::RUN_DONE_COUNT, Installer::REBUILD_SCOPE)->getData();
        if ($todo === $done) {
            $this->switchNewIndexToAlias($updateIndexElementMessage);

            SettingsStore::set(
                Installer::RUN_DONE_DATE,
                'finished: ' . date('Y-m-d H:i:s'),
                'string',
                Installer::REBUILD_SCOPE,
            );
            SettingsStore::set(
                Installer::RUN_HASH,
                '',
                'string',
                Installer::REBUILD_SCOPE,
            );
        }
    }

    /**
     * @throws \Elastic\Elasticsearch\Exception\ClientResponseException
     * @throws \Elastic\Elasticsearch\Exception\MissingParameterException
     * @throws \Elastic\Elasticsearch\Exception\ServerResponseException
     */
    public function switchNewIndexToAlias(RebuildUpdateIndexElementMessage $updateIndexElementMessage): void
    {
        $indices = $this->indexManager->getAllIndexNames($updateIndexElementMessage->getConfigReader());
        foreach ($indices as $alias) {
            if ($this->indexPersistenceService->aliasExists($alias)) {
                $index = $this->indexManager->findIndexNameByAlias($alias);
                $newIndexName = $this->getNewIndexName($index);
                $this->indexPersistenceService->createAlias($newIndexName, $alias);
                $this->indexPersistenceService->deleteIndex($index);
            }
        }
    }

    public function getNewIndexName(string $index): string
    {
        return str_ends_with($index, '-odd') ? str_replace('-odd', '', $index) . '-even' : str_replace('-even', '', $index) . '-odd';
    }
}
