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
        ];
    }
}
