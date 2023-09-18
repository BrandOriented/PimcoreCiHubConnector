<?php
/**
 * Simple REST Adapter.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 * @license    https://github.com/ci-hub-gmbh/SimpleRESTAdapterBundle/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\Handler;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\DeleteIndexElementMessage;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

final class DeleteIndexElementMessageHandler implements MessageHandlerInterface
{
    /**
     * @var IndexPersistenceService
     */
    private IndexPersistenceService $indexService;

    /**
     * @param IndexPersistenceService $indexService
     */
    public function __construct(IndexPersistenceService $indexService)
    {
        $this->indexService = $indexService;
    }

    /**
     * @param DeleteIndexElementMessage $message
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function __invoke(DeleteIndexElementMessage $message): void
    {
        $this->indexService->delete($message->getEntityId(), $message->getIndexName());
    }
}
