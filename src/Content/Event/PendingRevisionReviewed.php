<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\Review;

/**
 * Dispatched after someone reviewed a pending revision: approved it, asked for changes, or commented.
 *
 * @api
 */
final readonly class PendingRevisionReviewed
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public Review $review,
    ) {
    }
}
