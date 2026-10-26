<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\Comment;
use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after someone commented on a pending revision, or replied to a comment, e.g. to tell its
 * author.
 *
 * @api
 */
final readonly class PendingRevisionCommented
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public Comment $comment,
    ) {
    }
}
