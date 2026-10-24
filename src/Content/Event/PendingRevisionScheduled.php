<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a time was set for publishing a pending revision, or cleared ($pendingRevision->publishAt
 * is then null).
 *
 * @api
 */
final readonly class PendingRevisionScheduled
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public ?string $actorId,
    ) {
    }
}
