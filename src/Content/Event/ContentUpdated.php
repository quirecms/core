<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

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
    ) {
    }
}
