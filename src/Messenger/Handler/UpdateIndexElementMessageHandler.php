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

namespace App\Message\CIHUB\Handler;

use App\Message\CIHUB\UpdateIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\Element\ElementInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateIndexElementMessageHandler
{
    public function __construct(
        private IndexManager $indexManager,
        private IndexPersistenceService $indexPersistenceService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function __invoke(UpdateIndexElementMessage $updateIndexElementMessage): void
    {
        $element = match ($updateIndexElementMessage->getEntityType()) {
            'asset' => Asset::getById($updateIndexElementMessage->getEntityId()),
            'object' => AbstractObject::getById($updateIndexElementMessage->getEntityId()),
            default => null,
        };

        $folderClass = match($updateIndexElementMessage->getEntityType()) {
            'asset' => Asset\Folder::class,
            'object' => DataObject\Folder::class,
        };

        $endpointName = $updateIndexElementMessage->getEndpointName();

        if (!$element instanceof ElementInterface) {
            return;
        }

        $indexName = $this->indexManager->getIndexName($element, $endpointName);

        $this->indexPersistenceService->update($element, $endpointName, $indexName);
        $this->updateParentFolders($element->getParent(), $folderClass, $endpointName);
    }

    private function updateParentFolders(
        ?ElementInterface $element,
        string $folderClass,
        string $endpointName
    ): void {
        while ($element instanceof $folderClass && 1 !== $element->getId()) {
            $indexName = $this->indexManager->getIndexName($element, $endpointName);
            try {
                $this->indexPersistenceService->update($element, $endpointName, $indexName);
            } catch (ClientResponseException $e) {
                $this->logger->warning($e->getMessage(), [
                    'elementId' => $element->getId(),
                    'endpointName' => $endpointName,
                    'indexName' => $indexName,
                ]);
            } catch (ServerResponseException $e) {
                $this->logger->critical($e->getMessage(), [
                    'elementId' => $element->getId(),
                    'endpointName' => $endpointName,
                    'indexName' => $indexName,
                ]);
            }
            $element = $element->getParent();
        }
    }
}
