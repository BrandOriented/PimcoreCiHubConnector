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

use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\DeleteIndexElementMessage;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

#[AsMessageHandler]
final class DeleteIndexElementMessageHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

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
    public function __invoke(DeleteIndexElementMessage $message, Acknowledger $ack = null)
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

        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{0: DeleteIndexElementMessage, 1: Acknowledger}>
     */
    private function process(array $jobs): void
    {
        $params = [];
        $params['refresh'] = true;

        foreach ($jobs as [$message, $ack]) {
            assert($message instanceof DeleteIndexElementMessage);
            try {
                $params['body'][] = [
                    'delete' => [
                        '_index' => $message->getIndexName(),
                        '_id' => $message->getEntityId(),
                    ],
                ];
                $ack->ack($message);
            } catch (\Throwable $e) {
                $ack->nack($e);
            }
        }

        $this->indexPersistenceService->bulk($params);
    }

    // @phpstan-ignore-next-line
    private function shouldFlush(): bool
    {
        return 1 <= \count($this->jobs);
    }
}
