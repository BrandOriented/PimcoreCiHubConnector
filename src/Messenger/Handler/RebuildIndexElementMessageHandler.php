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
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\RebuildIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\RebuildUpdateIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use Doctrine\DBAL\Connection;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Tool\SettingsStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RebuildIndexElementMessageHandler
{
    public const CHUNK_SIZE = 100;

    public const TYPE_ASSET = 'asset';
    public const TYPE_OBJECT = 'object';

    public function __construct(
        private MessageBusInterface $messageBus,
        private IndexManager $indexManager,
        private IndexPersistenceService $indexPersistenceService,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(RebuildIndexElementMessage $rebuildIndexElementMessage): void
    {
        SettingsStore::set(Installer::RUN_HASH, $hash = uniqid('run', true), 'string', Installer::REBUILD_SCOPE);
        SettingsStore::set(Installer::RUN_DONE_COUNT, 0, 'int', Installer::REBUILD_SCOPE);

        $this->cleanAliases($rebuildIndexElementMessage);

        $todo = 0;

        if ($rebuildIndexElementMessage->configReader->isAssetIndexingEnabled()) {
            $this->rebuildType($rebuildIndexElementMessage, self::TYPE_ASSET, $hash, $todo);
        }
        if ($rebuildIndexElementMessage->configReader->isObjectIndexingEnabled()) {
            $this->rebuildType($rebuildIndexElementMessage, self::TYPE_OBJECT, $hash, $todo);
        }

        SettingsStore::set(Installer::RUN_TODO_COUNT, $todo, 'int', Installer::REBUILD_SCOPE);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function rebuildType(
        RebuildIndexElementMessage $rebuildIndexElementMessage, string $type, string $hash, int &$todo): void
    {
        $maxId = $this->getDb()->executeQuery("SELECT MAX(id) FROM {$type}s")->fetchNumeric()[0];
        for ($start = 0; $start <= $maxId; $start += self::CHUNK_SIZE) {
            $end = $start + self::CHUNK_SIZE;
            $items = $this->getDb()
                ->executeQuery("SELECT id, parentId FROM {$type}s WHERE id >= $start AND id < $end")
                ->fetchAllAssociative()
            ;
            foreach ($items as $item) {
                $this->messageBus->dispatch(
                    new RebuildUpdateIndexElementMessage($item['id'], $type, $rebuildIndexElementMessage->name, $hash, $rebuildIndexElementMessage->configReader));
                $this->enqueueParentFolders(
                    self::TYPE_ASSET === $type ? Asset::getById($item['parentId']) : DataObject::getById($item['parentId']),
                    self::TYPE_ASSET === $type ? Folder::class : DataObject\Folder::class,
                    $type,
                    $rebuildIndexElementMessage->name,
                    $hash,
                    $todo,
                    $rebuildIndexElementMessage->configReader
                );
                ++$todo;
            }
        }
    }

    private function enqueueParentFolders(
        ?ElementInterface $element,
        string $folderClass,
        string $type,
        string $name,
        string $hash,
        int &$todo,
        ConfigReader $configReader
    ): void {
        while ($element instanceof $folderClass && 1 !== $element->getId()) {
            $this->messageBus->dispatch(new RebuildUpdateIndexElementMessage($element->getId(), $type, $name, $hash, $configReader));
            $element = $element->getParent();
            ++$todo;
        }
    }

    protected function getDb(): Connection
    {
        return Db::get();
    }

    /**
     * @throws \Elastic\Elasticsearch\Exception\ClientResponseException
     * @throws \Elastic\Elasticsearch\Exception\MissingParameterException
     * @throws \Elastic\Elasticsearch\Exception\ServerResponseException
     */
    public function cleanAliases(RebuildIndexElementMessage $rebuildIndexElementMessage): void
    {
        $indices = $this->indexManager->getAllIndexNames($rebuildIndexElementMessage->configReader);
        foreach ($indices as $alias) {
            $index = $this->indexManager->findIndexNameByAlias($alias);
            $newIndexName = $this->getNewIndexName($index);
            if ($this->indexPersistenceService->indexExists($newIndexName)) {
                $this->indexPersistenceService->deleteIndex($newIndexName);
            }
            $mapping = $this->indexPersistenceService->getMapping($index)[$index]['mappings'];
            $this->indexPersistenceService->createIndex($newIndexName, $mapping);
        }
    }

    public function getNewIndexName(string $index): string
    {
        return str_ends_with($index, '-odd') ? str_replace('-odd', '', $index).'-even' : str_replace('-even', '', $index).'-odd';
    }
}
