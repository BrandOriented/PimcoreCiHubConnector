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

use CIHub\Bundle\SimpleRESTAdapterBundle\Messenger\AssetPreviewImageMessage;
use Pimcore\Model\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * Similar to the Pimcore one, but allows to queue previews generation for
 * thumbnails different than configured globally in system.
 *
 * Pimcore's AssetPreviewImageHandler never uses config if is is generated in queue
 * see: Pimcore\Messenger\Handler\AssetPreviewImageHandler
 */
#[AsMessageHandler]
class AssetPreviewImageMessageHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    public function __invoke(
        AssetPreviewImageMessage $message,
        Acknowledger $ack = null
    ): mixed {
        return $this->handle($message, $ack);
    }

    /**
     * @param list<array{0: AssetPreviewImageMessage, 1: Acknowledger}>
     */
    private function process(array $jobs): void
    {
        foreach ($jobs as [$message, $ack]) {
            assert($message instanceof AssetPreviewImageMessage);

            $id = $message->getId();
            $thumbnailName = $message->getThumbnailName();

            $this->logger->debug(sprintf('CIHUB: Processing thumbnail generation for the asset: %d', $id), [
                'thumbnailName' => $thumbnailName,
            ]);

            try {
                $asset = Asset::getById($id);

                $thumbnailConfig = match(true) {
                    $asset instanceof Asset\Image    => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
                    $asset instanceof Asset\Document => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
                    $asset instanceof Asset\Video    => Asset\Image\Thumbnail\Config::getByAutoDetect($thumbnailName),
                    default => null,
                };

                if ($thumbnailConfig instanceof Asset\Image\Thumbnail\Config) {
                    $thumbnail = match(true) {
                        $asset instanceof Asset\Image    => $asset->getThumbnail($thumbnailConfig),
                        $asset instanceof Asset\Document => $asset->getImageThumbnail($thumbnailConfig),
                        $asset instanceof Asset\Video    => $asset->getImageThumbnail($thumbnailConfig),
                        default => null,
                    };

                    if ($thumbnail instanceof Asset\Thumbnail\ThumbnailInterface) {
                        $thumbnail->generate(false);
                    }
                }

                $ack->ack($message);
            }
            catch (\Throwable $e) {
                $ack->nack($e);
            }
        }
    }
}
