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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler;

use App\Message\CIHUB\DeleteIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteIndexElementMessageHandler
{
    public function __construct(
        private IndexPersistenceService $indexPersistenceService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function __invoke(DeleteIndexElementMessage $message): void
    {
        $this->logger->debug(sprintf(
            'CIHub integration requested to remove %s element: %d',
            $message->getEntityType(),
            $message->getEntityId()
        ), [
            'entityId' => $message->getEntityId(),
            'entityType' => $message->getEntityType(),
            'endpointName' => $message->getEndpointName(),
            'indexName' => $message->getIndexName(),
        ]);

        $this->indexPersistenceService->delete(
            $message->getEntityId(),
            $message->getIndexName()
        );
//        try {
//            $this->indexPersistenceService->delete($element->getId(), $indexName);
//        } catch (ClientResponseException $e) {
//            // e.g. "Not Found". Could happen when index was not built/rebuilt
//            // for element for whatever reason. Just warn, do not interfere
//            // other code.
//            $this->logger->warning($e->getMessage(), [
//                'elementId' => $element->getId(),
//                'endpointName' => $endpointName,
//                'indexName' => $indexName,
//            ]);
//        } catch (ServerResponseException $e) {
//            $this->logger->critical($e->getMessage(), [
//                'elementId' => $element->getId(),
//                'endpointName' => $endpointName,
//                'indexName' => $indexName,
//            ]);
//        }
    }
}
