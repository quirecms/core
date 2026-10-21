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
}
