<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;

/**
 * Dispatched before a content item is changed. Listeners can add to or change the changes, or cancel them.
 *
 * @api
 */
final class ContentUpdating extends CancellableEvent
{
    /**
     * @param Content $content The item as it is now
     */
    public function __construct(
        public readonly Content $content,
        public readonly UpdateContent $command,
    ) {
    }
}
