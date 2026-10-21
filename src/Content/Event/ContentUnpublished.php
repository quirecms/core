<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a published item stopped being published, e.g. it went back to draft or was archived.
 *
 * @api
 */
final readonly class ContentUnpublished
{
    public function __construct(
        public Content $before,
        public Content $content,
    ) {
    }
}
