<?php

declare(strict_types=1);

namespace Quire\Theme\Seo;

use Quire\Head\Head;
use Quire\Head\HeadContributorInterface;

/**
 * The page's SEO tags in its head: the description, canonical URL, Open Graph and Twitter tags, and
 * structured data (see {@see SeoRenderer}). Registered at priority -100 by core's extend.php.
 *
 * @internal
 */
final readonly class SeoHeadContributor implements HeadContributorInterface
{
    public const int PRIORITY = -100;

    public function __construct(
        private SeoRenderer $seo,
    ) {
    }

    public function contribute(Head $head, array $context): void
    {
        $this->seo->contribute($head, $context);
    }
}
