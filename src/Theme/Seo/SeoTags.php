<?php

declare(strict_types=1);

namespace Quire\Theme\Seo;

/**
 * What quire_seo() puts in a page's <head>: the description, the canonical URL, Open Graph and Twitter
 * tags, and structured data (JSON-LD). Listeners of {@see \Quire\Theme\Event\SeoTagsBuilding} change it.
 *
 * @api
 */
final class SeoTags
{
    /**
     * @param string                     $type   Open Graph type: "website", or "article" for posts
     * @param list<array<string, mixed>> $jsonLd Structured data objects, e.g. a schema.org BlogPosting
     * @param array<string, string>      $meta   More <meta name="…" content="…"> tags, e.g. ["robots" => "noindex"]
     */
    public function __construct(
        public string $title,
        public string $siteName,
        public string $locale,
        public ?string $description = null,
        public ?string $canonicalUrl = null,
        public string $type = 'website',
        public ?\DateTimeImmutable $publishedAt = null,
        public ?\DateTimeImmutable $modifiedAt = null,
        public ?string $imageUrl = null,
        public array $jsonLd = [],
        public array $meta = [],
    ) {
    }
}
