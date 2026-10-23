<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a pending revision was deleted without publishing it.
 *
 * @api
 */
final readonly class PendingRevisionDiscarded
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public ?string $actorId,
    ) {
    }
}
