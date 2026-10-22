<?php

declare(strict_types=1);

namespace Quire\Content\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Content functions for templates:
 *
 *     {% for post in quire_content('post').latest.limit(5) %}…{% endfor %}
 *
 * @internal Registered automatically
 */
final class ContentExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('quire_content', [ContentRuntime::class, 'query']),
            new TwigFunction('quire_url', [ContentRuntime::class, 'url']),
            new TwigFunction('quire_listing_url', [ContentRuntime::class, 'listingUrl']),
            new TwigFunction('quire_home_url', [ContentRuntime::class, 'homeUrl']),
            new TwigFunction('quire_body', [ContentRuntime::class, 'body'], ['is_safe' => ['html']]),
            new TwigFunction('quire_excerpt', [ContentRuntime::class, 'excerpt']),
        ];
    }
}
