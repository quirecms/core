<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * @api
 */
final class ContentNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('No content item with ID "%s" exists.', $id));
    }

    public static function forPendingRevision(string $id): self
    {
        return new self(\sprintf('No pending revision with ID "%s" exists. It may have been published or discarded.', $id));
    }
}
