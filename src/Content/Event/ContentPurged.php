<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a content item was deleted permanently. The item is the last state it had.
 *
 * @api
 */
final readonly class ContentPurged
{
    public function __construct(
        public Content $content,
    ) {
    }
}
