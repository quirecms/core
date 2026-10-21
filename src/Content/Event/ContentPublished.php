<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;

/**
 * Dispatched after a content item was published, including a scheduled item once its date has passed and `quire:content:publish-due` ran.
 *
 * @api
 */
final readonly class ContentPublished
{
    public function __construct(
        public Content $content,
    ) {
    }
}
