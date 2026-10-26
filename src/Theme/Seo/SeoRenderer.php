<?php

declare(strict_types=1);

namespace Quire\Theme\Seo;

use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Quire\Theme\Event\SeoTagsBuilding;
use Quire\Theme\Twig\SiteView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds and prints the SEO tags of a page from its template's variables; and builds an item's without a
 * page, e.g. for the admin's preview of how it looks in search results and when shared.
 *
 * Pages without an image of their own share the site's (the setting "site.share_image").
 *
 * @api
 */
final readonly class SeoRenderer
{
    /** Setting: the image shared with pages that have none of their own, a URL or a path on the site */
    public const string SHARE_IMAGE_SETTING = 'site.share_image';

    private const int DESCRIPTION_LENGTH = 160;

    public function __construct(
        private SiteView $site,
        private ContentRenderer $renderer,
        private ContentTypeRegistry $types,
        private EventDispatcherInterface $dispatcher,
        private Permalinks $permalinks,
        private Settings $settings,
    ) {
    }

    /**
     * The tags of an item's page, as its page would print them; listeners of {@see SeoTagsBuilding} get
     * them too, with the item as the only variable of the context.
     */
    public function tagsFor(Content $content): SeoTags
    {
        $context = ['content' => $content, 'canonical_url' => $this->permalinks->url($content, UrlGeneratorInterface::ABSOLUTE_URL)];
        $tags = $this->build($context, $content);
        $this->dispatcher->dispatch(new SeoTagsBuilding($tags, $content, $context));

        return $tags;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(array $context): string
    {
        $content = ($context['content'] ?? null) instanceof Content ? $context['content'] : null;
        $tags = $this->build($context, $content);
        $this->dispatcher->dispatch(new SeoTagsBuilding($tags, $content, $context));

        return $this->html($tags);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function build(array $context, ?Content $content): SeoTags
    {
        $canonical = \is_string($context['canonical_url'] ?? null) ? $context['canonical_url'] : null;
        $tags = new SeoTags(title: $this->site->title(), siteName: $this->site->title(), locale: $this->site->locale(), canonicalUrl: $canonical, imageUrl: $this->shareImage());
        $siteDescription = '' === $this->site->description() ? null : $this->site->description();

        if (!$content instanceof Content) {
            $type = ($context['type'] ?? null) instanceof ContentTypeDefinition ? $context['type'] : null;
            $isHome = true === ($context['is_home'] ?? false);
            $tags->title = !$type instanceof ContentTypeDefinition || $isHome ? $this->site->title() : $type->pluralLabel;
            $tags->description = $siteDescription;

            if ($isHome) {
                $tags->jsonLd[] = array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $this->site->title(),
                    'url' => $this->site->url(),
                    'description' => $siteDescription,
                    'inLanguage' => $this->site->locale(),
                ], static fn (mixed $value): bool => null !== $value);
            }

            return $tags;
        }

        $excerpt = $this->renderer->excerpt($content, self::DESCRIPTION_LENGTH);
        $tags->title = $content->title;
        $tags->locale = $content->locale;
        $tags->description = '' === $excerpt ? $siteDescription : $excerpt;

        if ($this->types->has($content->type) && $this->types->get($content->type)->has(Capability::SCHEDULING)) {
            $tags->type = 'article';
            $tags->publishedAt = $content->publishedAt;
            $tags->modifiedAt = $content->updatedAt;
            $tags->jsonLd[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $content->title,
                'description' => $tags->description,
                'url' => $canonical,
                'datePublished' => $content->publishedAt?->format(\DATE_ATOM),
                'dateModified' => $content->updatedAt->format(\DATE_ATOM),
                'inLanguage' => $content->locale,
                'publisher' => ['@type' => 'Organization', 'name' => $this->site->title(), 'url' => $this->site->url()],
            ], static fn (mixed $value): bool => null !== $value);
        }

        return $tags;
    }

    /**
     * The site's share image, as an absolute URL: a path is on the site.
     */
    private function shareImage(): ?string
    {
        $image = $this->settings->get(self::SHARE_IMAGE_SETTING);

        return match (true) {
            !\is_string($image) || '' === $image => null,
            str_starts_with($image, '/') && !str_starts_with($image, '//') => rtrim($this->site->url(), '/').$image,
            default => $image,
        };
    }

    private function html(SeoTags $tags): string
    {
        $lines = [];
        $meta = static function (string $attribute, string $name, ?string $value) use (&$lines): void {
            if (null !== $value && '' !== $value) {
                $lines[] = \sprintf('<meta %s="%s" content="%s">', $attribute, $name, htmlspecialchars($value));
            }
        };

        $meta('name', 'description', $tags->description);

        if (null !== $tags->canonicalUrl) {
            $lines[] = \sprintf('<link rel="canonical" href="%s">', htmlspecialchars($tags->canonicalUrl));
        }

        foreach ($tags->meta as $name => $value) {
            $meta('name', $name, $value);
        }

        $meta('property', 'og:type', $tags->type);
        $meta('property', 'og:title', $tags->title);
        $meta('property', 'og:description', $tags->description);
        $meta('property', 'og:url', $tags->canonicalUrl);
        $meta('property', 'og:site_name', $tags->siteName);
        // Open Graph writes locales as "en_US".
        $meta('property', 'og:locale', str_replace('-', '_', $tags->locale));
        $meta('property', 'og:image', $tags->imageUrl);
        $meta('property', 'article:published_time', $tags->publishedAt?->format(\DATE_ATOM));
        $meta('property', 'article:modified_time', $tags->modifiedAt?->format(\DATE_ATOM));
        $meta('name', 'twitter:card', null === $tags->imageUrl ? 'summary' : 'summary_large_image');

        foreach ($tags->jsonLd as $data) {
            // Escaped so the data can't close the script element.
            $lines[] = '<script type="application/ld+json">'.json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR).'</script>';
        }

        return implode("\n", $lines)."\n";
    }
}
