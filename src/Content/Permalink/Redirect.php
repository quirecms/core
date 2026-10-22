<?php

declare(strict_types=1);

namespace Quire\Content\Permalink;

/**
 * A redirect added by hand, from a URL path to a content item or to another URL.
 *
 * @api
 */
final readonly class Redirect
{
    /**
     * @param string      $source          Path relative to where Quire is mounted, e.g. "/2019/05/hello"
     * @param string|null $targetContentId The item to redirect to, wherever its URL is now
     * @param string|null $targetUrl       Otherwise: an absolute URL, or a path relative to where Quire is mounted
     */
    public function __construct(
        public string $source,
        public ?string $targetContentId,
        public ?string $targetUrl,
        public int $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
