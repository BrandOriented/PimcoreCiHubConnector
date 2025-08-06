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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Repository;

use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Configuration\Dao;

final class DataHubConfigurationRepository
{
    public function findOneByName(string $name): ?Configuration
    {
        return Configuration::getByName($name);
    }

    /**
     * @return Configuration[]
     */
    public function all(): array
    {
        return Configuration::getList();
    }

    /**
     * @param array<int, string> $allowedConfigTypes
     *
     * @return Configuration[]
     */
    public function getList(array $allowedConfigTypes = []): array
    {
        $list = Configuration::getList();

        if ([] !== $allowedConfigTypes) {
            $list = array_filter($list, static fn ($config): bool => \in_array($config->getType(), $allowedConfigTypes, true));
        }

        return $list;
    }
}
