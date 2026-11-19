<?php

declare(strict_types=1);

namespace Quire\Theme\Navigation;

/**
 * Where the site's navigation comes from. Core's default lists the visible top-level pages; a menus
 * extension replaces this provider (decorating it), never the themes, which only read Navigation.
 *
 * Internal until a second provider exists (a menus extension): widening it later is free, narrowing
 * it would break someone.
 *
 * @internal
 */
interface NavigationProviderInterface
{
    /**
     * @return list<NavigationItem> In order, `current` not yet set
     */
    public function items(): array;
}
