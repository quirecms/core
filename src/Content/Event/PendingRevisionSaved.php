<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a pending revision was created or saved, e.g. by an autosave.
 *
 * @api
 */
final readonly class PendingRevisionSaved
{
    public function __construct(
        public PendingRevision $pendingRevision,
        /** Whether it was just created */
        public bool $created,
    ) {
    }
}
