<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a content item was created.
 *
 * @api
 */
final readonly class ContentCreated
{
    public function __construct(
        public Content $content,
    ) {
    }
}
