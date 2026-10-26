<?php

declare(strict_types=1);

namespace Quire\Theme;

use Quire\Content\Content;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Theme\Internal\AssetsRenderer;
use Quire\Theme\Seo\SeoRenderer;
use Quire\Theme\Seo\SeoTags;
use Twig\Environment;

/**
 * How an item will appear before it does: its card in lists, as the site's theme draws it, and its
 * tags for search results and for sharing. For admin interfaces' previews; nothing is published.
 *
 * The card is the theme's content/<type>/_card.html.twig or content/_card.html.twig, rendered alone, so
 * it must not need the request (see docs/themes.md).
 *
 * @api
 */
final readonly class ContentPreviews
{
    public function __construct(
        private Environment $twig,
        private ContentTypeRegistry $types,
        private AssetsRenderer $assets,
        private SeoRenderer $seo,
    ) {
    }

    /**
     * The item's card, as HTML.
     */
    public function card(Content $item): string
    {
        $type = $this->types->get($item->type);

        return $this->twig->resolveTemplate([\sprintf('content/%s/_card.html.twig', $type->id), 'content/_card.html.twig'])->render(['item' => $item, 'type' => $type]);
    }

    /**
     * The item's card in a page of its own, with the theme's stylesheets and no scripts: for an iframe,
     * e.g. one with sandbox="" and srcdoc.
     */
    public function cardDocument(Content $item): string
    {
        return \sprintf(
            "<!doctype html>\n<html lang=\"%s\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"robots\" content=\"noindex\">\n%s<style>body { margin: 0; padding: 1rem; }</style>\n</head>\n<body class=\"quire-preview\">\n%s\n</body>\n</html>\n",
            htmlspecialchars($item->locale),
            $this->assets->stylesheets('site'),
            $this->card($item),
        );
    }

    /**
     * The item's tags, as its page prints them: the title, description, URL and image of search results
     * and shared links.
     */
    public function seo(Content $item): SeoTags
    {
        return $this->seo->tagsFor($item);
    }
}
