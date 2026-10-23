<?php

declare(strict_types=1);

namespace Quire\Storage\Exception;

/**
 * No storage disk has this name.
 *
 * @api
 */
final class UnknownDiskException extends \InvalidArgumentException
{
    /**
     * @param list<string> $known
     */
    public static function named(string $name, array $known): self
    {
        return new self(\sprintf('There is no storage disk "%s". Disks: %s.', $name, [] === $known ? 'none' : implode(', ', $known)));
    }
}
