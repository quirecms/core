<?php

declare(strict_types=1);

namespace Quire\Theme\Event;

use Quire\Content\Content;
use Quire\Theme\Seo\SeoTags;

/**
 * Dispatched when quire_seo() is about to print a page's SEO tags: change them through $tags, e.g. to
 * add an image or a noindex.
 *
 *     #[AsEventListener]
 *     public function __invoke(SeoTagsBuilding $event): void
 *     {
 *         $event->tags->imageUrl ??= $this->images->cover($event->content);
 *     }
 *
 * @api
 */
final readonly class SeoTagsBuilding
{
    /**
     * @param Content|null         $content The item the page shows, if any
     * @param array<string, mixed> $context The variables of the template that called quire_seo()
     */
    public function __construct(
        public SeoTags $tags,
        public ?Content $content,
        public array $context,
    ) {
    }
}
