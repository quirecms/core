<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a pending revision was published: merged into the item, which is now live with the
 * changes. {@see ContentUpdated} is dispatched too, when something changed.
 *
 * @api
 */
final readonly class PendingRevisionPublished
{
    public function __construct(
        public PendingRevision $pendingRevision,
        public Content $content,
        /** Who published it */
        public ?string $actorId,
    ) {
    }
}
