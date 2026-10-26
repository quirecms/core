<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;

/**
 * Dispatched after a content item was changed.
 *
 * @api
 */
final readonly class ContentUpdated
{
    public function __construct(
        public Content $before,
        public Content $content,
        /** The pending revision whose changes this update published, if it did; {@see PendingRevisionPublished} follows */
        public ?PendingRevision $publishedPendingRevision = null,
    ) {
    }
}
