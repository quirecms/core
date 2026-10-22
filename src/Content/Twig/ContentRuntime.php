<?php

declare(strict_types=1);

namespace Quire\Content\Twig;

use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\QueryRunner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * @internal Loaded by Twig when a template calls a content function, so templates without one don't
 *           build the query services
 */
final readonly class ContentRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private QueryRunner $queries,
        private Permalinks $permalinks,
        private ContentRenderer $renderer,
    ) {
    }

    /**
     * The item's URL: `{{ quire_url(post) }}`, or with the domain: `{{ quire_url(post, true) }}`.
     */
    public function url(Content $content, bool $absolute = false): string
    {
        return $this->permalinks->url($content, $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * The URL of a type's list: `{{ quire_listing_url('post') }}`, `{{ quire_listing_url('post', 2) }}`.
     */
    public function listingUrl(string $type, int $page = 1, bool $absolute = false): string
    {
        return $this->permalinks->listingUrl($type, $page, $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public function homeUrl(bool $absolute = false): string
    {
        return $this->permalinks->homeUrl($absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    /**
     * The item's body as HTML: `{{ quire_body(post) }}`.
     */
    public function body(Content $content): string
    {
        return $this->renderer->body($content);
    }

    /**
     * A plain-text summary of the item: `{{ quire_excerpt(post) }}`, `{{ quire_excerpt(post, 120) }}`.
     */
    public function excerpt(Content $content, int $length = 200): string
    {
        return $this->renderer->excerpt($content, $length);
    }

    /**
     * A lazy query: it runs when the template iterates it, with at most {@see ContentQuery::TEMPLATE_MAX_LIMIT} items.
     */
    public function query(string $type, string ...$types): ContentQuery
    {
        return $this->queries->query([$type, ...array_values($types)], template: true);
    }
}
