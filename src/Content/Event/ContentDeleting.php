<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched before a content item is moved to the trash. Listeners can cancel it.
 *
 * @api
 */
final class ContentDeleting extends CancellableEvent
{
    public function __construct(
        public readonly Content $content,
    ) {
    }
}
