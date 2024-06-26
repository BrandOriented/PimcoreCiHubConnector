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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Model;

final class UploadParts implements UploadPartsInterface
{
    private array $parts = [];

    public function __construct(array $parts = [])
    {
        foreach ($parts as $part) {
            if (\is_array($part)) {
                $this->add(new UploadPart($part));
            } elseif ($part instanceof UploadPartInterface) {
                $this->add($part);
            }
        }
    }

    public function toArray(): array
    {
        return array_map(static fn (UploadPartInterface $uploadPart): array => $uploadPart->toArray(), $this->parts);
    }

    public function add(UploadPartInterface $uploadPart): UploadPartsInterface
    {
        $this->parts[] = $uploadPart;

        return $this;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->parts);
    }

    public function count(): int
    {
        return \count($this->parts);
    }
}
