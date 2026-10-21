<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after any status transition, including those that extensions add.
 *
 * @api
 */
final readonly class ContentStatusChanged
{
    public function __construct(
        public Content $before,
        public Content $content,
        public string $transition,
    ) {
    }
}
