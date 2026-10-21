<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * Someone else saved the item after the editor loaded it. Reload the item, reapply the changes and save again.
 *
 * @api
 */
final class ContentConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $id,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(\sprintf('The content item "%s" was changed by someone else: you edited version %d, but it is now at version %d. Reload it and apply your changes again.', $id, $expectedVersion, $currentVersion));
    }
}
