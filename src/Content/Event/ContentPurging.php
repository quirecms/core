<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched before a content item is deleted permanently. Listeners can cancel it, or clean up data that database foreign keys don't cover.
 *
 * @api
 */
final class ContentPurging extends CancellableEvent
{
    public function __construct(
        public readonly Content $content,
    ) {
    }
}
