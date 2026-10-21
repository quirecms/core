<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched before a content item becomes published. Listeners can cancel it.
 *
 * @api
 */
final class ContentPublishing extends CancellableEvent
{
    /**
     * @param Content $content The item as it is now
     */
    public function __construct(
        public readonly Content $content,
        public readonly string $transition,
    ) {
    }
}
