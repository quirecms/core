<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a content item was moved to the trash.
 *
 * @api
 */
final readonly class ContentDeleted
{
    public function __construct(
        public Content $content,
    ) {
    }
}
