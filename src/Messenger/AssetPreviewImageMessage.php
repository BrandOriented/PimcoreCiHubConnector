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
 * Similar to the Pimcore one, but allows to queue previews generation for
 * thumbnails different than configured globally in system.
 */
class AssetPreviewImageMessage
{
    /**
     * @param int $id ID of Asset element
     * @param string|array $thumbnailName Name of thumbnail. To be used to get thumbnail configuration.
     */
    public function __construct(
        protected int $id,
        protected string|array $thumbnailName,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getThumbnailName(): string|array
    {
        return $this->thumbnailName;
    }
}
