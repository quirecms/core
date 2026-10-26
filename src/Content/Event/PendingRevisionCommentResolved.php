<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\Comment;
use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a thread of comments on a pending revision was marked resolved, or open again.
 *
 * @api
 */
final readonly class PendingRevisionCommentResolved
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public Comment $comment,
        public ?string $actorId,
    ) {
    }
}
