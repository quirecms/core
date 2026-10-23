<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * The pending revision was saved again after the editor or reviewer loaded it, e.g. in another tab.
 * Reload it, so nobody publishes or approves what they haven't seen.
 *
 * @api
 */
final class PendingRevisionConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $id,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(\sprintf('The pending revision "%s" was changed: you have version %d, but it is now at version %d. Reload it and try again.', $id, $expectedVersion, $currentVersion));
    }
}
