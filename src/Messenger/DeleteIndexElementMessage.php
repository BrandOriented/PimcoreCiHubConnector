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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Messenger;

/**
 * Note that entity (Pimcore Asset/Object) will already be deleted, yet its existence
 * is not needed to update ES/CIHUB indexes/changes
 */
final readonly class DeleteIndexElementMessage
{
    public function __construct(
        private int $entityId,
        private string $entityType,
        private string $endpointName,
        private string $indexName,
    ) {
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEndpointName(): string
    {
        return $this->endpointName;
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }
}
