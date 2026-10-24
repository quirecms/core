<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched when a pending revision couldn't be published at its time, e.g. because of a conflict
 * with changes published meanwhile, or a missing approval. It stays pending, without a time; its
 * publishProblem says why. Listen to it to tell its author, e.g. by email.
 *
 * @api
 */
final readonly class PendingRevisionPublishFailed
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public string $reason,
    ) {
    }
}
