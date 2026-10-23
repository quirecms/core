<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after the author of a pending revision asked for it to be reviewed.
 *
 * @api
 */
final readonly class PendingRevisionSubmitted
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public ?string $actorId,
    ) {
    }
}
