<?php

declare(strict_types=1);

namespace Quire\Theme\Navigation\Internal;

use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Theme\Navigation\NavigationItem;
use Quire\Theme\Navigation\NavigationProviderInterface;

/**
 * Core's navigation: the top-level items of the hierarchical types (pages) visitors can see, in the
 * order editors gave them (their position). A header's worth.
 *
 * @internal
 */
final readonly class TopLevelPages implements NavigationProviderInterface
{
    /** A header's worth: more is a menu's job */
    public const int LIMIT = 8;

    public function __construct(
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private Permalinks $permalinks,
    ) {
    }

    public function items(): array
    {
        $types = array_values(array_map(static fn (ContentTypeDefinition $type): string => $type->id, $this->types->withCapability(Capability::HIERARCHICAL)));

        if ([] === $types) {
            return [];
        }

        $pages = $this->content->query(...$types)->childrenOf(null)->orderBy('position')->limit(self::LIMIT)->get();

        return array_map(fn (Content $page): NavigationItem => new NavigationItem($page->title, $this->permalinks->url($page), $page), $pages);
    }
}
