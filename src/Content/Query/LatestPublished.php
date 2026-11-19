<?php

declare(strict_types=1);

namespace Quire\Content\Query;

use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The latest published items of the listable types, kept in the cache for pages many visitors and
 * bots hit without needing them fresh to the second, such as "page not found" (`quire_latest()`).
 *
 * One list serves everyone, so it's made as a visitor who isn't signed in would see it, whoever's
 * request makes it: never a members-only item, nor one an extension's rule shows only to some. One
 * list per language (the page's), of items in it. Dropped when an item is published, unpublished,
 * changed, trashed, restored or deleted, so a title just taken down isn't shown; a short lifetime
 * bounds anything else (a scheduled item that went live).
 *
 * @api
 */
final readonly class LatestPublished
{
    public const string CACHE_KEY = 'quire.latest_published';

    /** At most this many are kept */
    public const int KEPT = 10;

    private const int LIFETIME = 300;

    public function __construct(
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private CacheInterface $cache,
        private TokenStorageInterface $tokens,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return list<Content> Newest first, in the page's language
     */
    public function items(int $limit = 5, ?string $locale = null): array
    {
        $locale ??= $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        // The version changes when anything is forgotten: every language's list goes at once
        $version = $this->cache->get(self::CACHE_KEY.'.version', static function (ItemInterface $item): string {
            $item->expiresAfter(self::LIFETIME * 12);

            return bin2hex(random_bytes(6));
        });
        $key = self::CACHE_KEY.'.'.$version.'.'.preg_replace('/[^A-Za-z0-9_]/', '_', $locale);

        /** @var list<Content> $items */
        $items = $this->cache->get($key, function (ItemInterface $item) use ($locale): array {
            $item->expiresAfter(self::LIFETIME);

            return $this->asVisitor(function () use ($locale): array {
                $types = array_values(array_map(static fn (ContentTypeDefinition $type): string => $type->id, $this->types->withCapability(Capability::LISTABLE)));

                return [] === $types ? [] : $this->content->query(...$types)->published()->locale($locale)->latest()->limit(self::KEPT)->get();
            });
        });

        return \array_slice($items, 0, max(0, min($limit, self::KEPT)));
    }

    public function forget(): void
    {
        $this->cache->delete(self::CACHE_KEY.'.version');
    }

    /**
     * Runs the query with nobody signed in, then puts the request's user back.
     *
     * @template T
     *
     * @param \Closure(): T $query
     *
     * @return T
     */
    private function asVisitor(\Closure $query): mixed
    {
        $token = $this->tokens->getToken();
        $this->tokens->setToken(null);

        try {
            return $query();
        } finally {
            $this->tokens->setToken($token);
        }
    }
}
