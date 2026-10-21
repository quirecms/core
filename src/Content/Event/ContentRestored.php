<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a content item was restored from the trash.
 *
 * @api
 */
final readonly class ContentRestored
{
    public function __construct(
        public Content $content,
    ) {
    }
}
