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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Services;

use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\AssetPreviewImageMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class ThumbnailService
{
    public static function getAllowedFormat(string $format, array $allowed = [], string $fallback = 'png'): string
    {
        $typeMappings = [
            'jpg' => 'jpeg',
            'tif' => 'tiff',
        ];

        if (isset($typeMappings[$format])) {
            $format = $typeMappings[$format];
        }

        return \in_array($format, $allowed, true) ? $format : $fallback;
    }

    private static function getLock(AssetPreviewImageMessage $message): SharedLockInterface {
        /** @var LockFactory $lockFactory */
        $lockFactory = \Pimcore::getContainer()->get(LockFactory::class);

        return $lockFactory->createLock(
            sprintf('ciHub-preview-%d', $message->getId()),
            null,
            false,// no auto-release
        );
    }

    public static function isMessageQueued(AssetPreviewImageMessage $message): bool
    {
        $lock = self::getLock($message);
        $alreadyInQueue = $lock->isAcquired();

        if ($lock->isExpired()) {
            $lock->release();
        }
        return $alreadyInQueue;
    }

    public static function lockMessage(AssetPreviewImageMessage $message): bool
    {
        return self::getLock($message)->acquire(false);
    }

    public static function releaseMessage(AssetPreviewImageMessage $message): void
    {
        self::getLock($message)->release();
    }
}
