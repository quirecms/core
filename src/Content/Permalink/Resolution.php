<?php

declare(strict_types=1);

namespace Quire\Content\Permalink;

use Quire\Content\Content;

/**
 * What a URL leads to: a content item, a type's listing, or a redirect.
 *
 * @internal
 */
final readonly class Resolution
{
    public const string CONTENT = 'content';

    public const string LISTING = 'listing';

    public const string REDIRECT = 'redirect';

    private function __construct(
        public string $kind,
        public ?Content $content = null,
        public ?string $type = null,
        public ?string $url = null,
        public int $status = 200,
    ) {
    }

    public static function content(Content $content): self
    {
        return new self(self::CONTENT, content: $content);
    }

    public static function listing(string $type): self
    {
        return new self(self::LISTING, type: $type);
    }

    public static function redirect(string $url, int $status = 301): self
    {
        return new self(self::REDIRECT, url: $url, status: $status);
    }
}
