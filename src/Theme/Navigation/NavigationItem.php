<?php

declare(strict_types=1);

namespace Quire\Theme\Navigation;

use Quire\Content\Content;

/**
 * One link of the site's navigation: what it says, where it goes, and whether it's the page being
 * shown (or one of its ancestors: `current` then says "page", "section" or false).
 *
 * @api
 */
final readonly class NavigationItem
{
    /**
     * @param 'page'|'section'|false $current
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?Content $content = null,
        public string|false $current = false,
    ) {
    }
}
