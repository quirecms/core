<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Command\CreateContent;

/**
 * Dispatched before a content item is created. Listeners can change the request, or cancel it.
 *
 * @api
 */
final class ContentCreating extends CancellableEvent
{
    public function __construct(
        public readonly CreateContent $command,
    ) {
    }
}
