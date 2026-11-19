<?php

declare(strict_types=1);

namespace Quire\Content\Permalink;

use Doctrine\DBAL\Connection;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Permission\Internal\Viewer;
use Quire\Settings\Settings;

/**
 * Finds what a URL path leads to.
 *
 * In order: a type's listing; an item at its current URL (any other spelling of that URL, e.g. with a
 * trailing slash or the wrong date, redirects to it); a redirect added by hand; an item at one of its
 * earlier paths, or at a URL in a type's earlier pattern, which redirects to its current URL. Only items
 * visitors may see are found.
 *
 * @internal
 */
final readonly class PermalinkResolver
{
    public function __construct(
        private ContentRepository $content,
        private Permalinks $permalinks,
        private Redirects $redirects,
        private ContentTypeRegistry $types,
        private Settings $settings,
        private Connection $connection,
        private Viewer $viewer,
    ) {
    }

    /**
     * @param string $path Relative to where Quire is mounted, as requested, e.g. "/blog/hello"
     */
    public function resolve(string $path): ?Resolution
    {
        $normalized = '/'.trim(mb_strtolower($path), '/');

        foreach (array_keys($this->types->withCapability(Capability::LISTABLE)) as $type) {
            if ($normalized !== $this->permalinks->configuredListingPath($type)) {
                continue;
            }

            // The homepage may show the listing: then it has one URL, the homepage's. That follows a setting,
            // not a move, so the redirect is temporary: a browser that kept it would loop once the setting changes.
            return $path === $this->permalinks->listingPath($type) ? Resolution::listing($type) : Resolution::redirect($this->permalinks->listingUrl($type), 302);
        }

        $patterns = $this->permalinks->patterns();

        foreach ($patterns as $type => $pattern) {
            $item = $this->find($type, $pattern, $normalized, earlier: false);

            if ($item instanceof Content) {
                return $this->permalinks->path($item) === $path ? Resolution::content($item) : $this->toItem($item);
            }
        }

        // Unpublished items, for those who may preview them.
        foreach ($patterns as $type => $pattern) {
            $item = $this->viewer->canPreviewType($type) ? $this->find($type, $pattern, $normalized, earlier: false, drafts: true) : null;

            if ($item instanceof Content) {
                return $this->permalinks->path($item) === $path ? Resolution::content($item) : $this->toItem($item);
            }
        }

        $redirect = $this->redirects->find($normalized);

        if ($redirect instanceof Redirect) {
            $target = $this->redirectTarget($redirect);

            if (null !== $target) {
                return Resolution::redirect($target, $redirect->status);
            }
        }

        // Earlier paths in the current patterns, then any path in earlier patterns.
        foreach ($patterns as $type => $pattern) {
            $item = $this->find($type, $pattern, $normalized, earlier: true);

            if ($item instanceof Content) {
                return $this->toItem($item);
            }
        }

        foreach ($this->earlierPatterns() as [$type, $pattern]) {
            $item = $this->find($type, $pattern, $normalized, earlier: false) ?? $this->find($type, $pattern, $normalized, earlier: true);

            if ($item instanceof Content) {
                return $this->toItem($item);
            }
        }

        return null;
    }

    /**
     * The visible item of the type at the path, when the path fits the pattern.
     *
     * Items of types the viewer may not view are found too, so the page can ask them to sign in. With
     * $drafts, unpublished items are found as well (not trashed ones).
     */
    private function find(string $type, PermalinkPattern $pattern, string $path, bool $earlier, bool $drafts = false): ?Content
    {
        $values = $pattern->match($path);

        if (null === $values) {
            return null;
        }

        $query = $this->content->query($type);

        if ($drafts) {
            $query = $query->withoutVisibilityScope()->whereSql('c.deleted_at IS NULL');
        } elseif (!$this->viewer->canViewType($type)) {
            $public = ContentQuery::publicCondition();
            $query = $query->withoutVisibilityScope()->whereSql($public['sql'], $public['params']);
        }

        if (isset($values['id'])) {
            if ($earlier) {
                return null;
            }

            $query = $query->where('id', '=', $values['id']);
        } else {
            $value = $values['path'] ?? $values['slug'] ?? throw new \LogicException('Patterns identify items by {id}, {path} or {slug}.');
            $column = isset($values['path']) || !$this->types->get($type)->has(Capability::HIERARCHICAL) ? 'path' : 'slug';
            $query = $earlier
                ? $query->whereSql('EXISTS (SELECT 1 FROM quire.content_path_history h WHERE h.content_id = c.id AND h.path = ?)', [$value])
                : $query->where($column, '=', $value);
        }

        $items = $query->orderBy('created_at')->limit(10)->get();
        $locale = $this->settings->getString('site.locale');

        // Translations can share a path: prefer the site's language.
        return array_find($items, static fn (Content $item): bool => $item->locale === $locale) ?? $items[0] ?? null;
    }

    private function redirectTarget(Redirect $redirect): ?string
    {
        if (null !== $redirect->targetUrl) {
            return str_starts_with($redirect->targetUrl, '/') ? $this->permalinks->pathUrl($redirect->targetUrl) : $redirect->targetUrl;
        }

        $item = $this->content->query(...array_keys($this->types->all()))->where('id', '=', $redirect->targetContentId)->first();

        return $item instanceof Content ? $this->permalinks->url($item) : null;
    }

    /**
     * @return list<array{string, PermalinkPattern}> Type and pattern, newest first
     */
    private function earlierPatterns(): array
    {
        $patterns = [];

        foreach ($this->connection->fetchAllAssociative('SELECT type, pattern FROM quire.permalink_history ORDER BY replaced_at DESC, pattern') as $row) {
            if (!\is_string($row['type']) || !\is_string($row['pattern']) || !$this->types->has($row['type'])) {
                continue;
            }

            try {
                $patterns[] = [$row['type'], PermalinkPattern::parse($row['pattern'], $this->types->get($row['type'])->has(Capability::HIERARCHICAL))];
            } catch (\InvalidArgumentException) {
                // A pattern that's no longer valid for the type can't be resolved.
            }
        }

        return $patterns;
    }

    /**
     * To an item's current URL: for good (an old address, another spelling), except to the homepage, where
     * a page is only while the homepage setting says so.
     */
    private function toItem(Content $item): Resolution
    {
        $url = $this->permalinks->url($item);

        return Resolution::redirect($url, '/' === $this->permalinks->path($item) ? 302 : 301);
    }
}
