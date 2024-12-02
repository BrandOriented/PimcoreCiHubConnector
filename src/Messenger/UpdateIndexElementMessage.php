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

use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;

final readonly class UpdateIndexElementMessage
{
    public function __construct(
        private int $entityId,
        private string $entityType,
        private string $endpointName,
        private string|null $hash,
        private ConfigReader $configReader
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

    public function getHash(): string|null
    {
        return $this->hash;
    }

    public function getConfigReader(): ConfigReader
    {
        return $this->configReader;
    }
}
