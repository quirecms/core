<?php

declare(strict_types=1);

namespace Quire\Content\Twig;

use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\QueryRunner;
use Quire\Content\Query\LatestPublished;
use Quire\Content\Revision\PublicChange;
use Quire\Content\Revision\PublicHistory;
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
        private LatestPublished $latest,
        private PublicHistory $history,
    ) {
    }

    /**
     * A published item's public history, newest first: what its authors told readers when they
     * published a change, "Updated" or "Corrected" (`{% for change in quire_history(post) %}`). Only
     * what was published; the item's own "Published" date is the template's.
     *
     * @return list<PublicChange>
     */
    public function history(Content $content): array
    {
        return $this->history->of($content);
    }

    /**
     * The latest published items, from the cache, for pages many visitors and bots hit such as
     * "page not found": `{% for post in quire_latest(3) %}`. Only what visitors may see.
     *
     * @return list<Content>
     */
    public function latest(int $limit = 5): array
    {
        return $this->latest->items($limit);
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
