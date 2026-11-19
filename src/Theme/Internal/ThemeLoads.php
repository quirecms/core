<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Csp\Csp;
use Quire\Head\Internal\HeadRenderer;
use Quire\Theme\TemplateUrls;
use Quire\Theme\Themes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a page of the active theme loads nothing from other sites (the default theme's "this site
 * loads nothing from other companies"): the policy Quire sends (Csp::loadsOnlyFromThisSite()), the
 * page's head (a cross-site preconnect, dns-prefetch, prefetch, preload or modulepreload, which no
 * policy governs), and the active theme's own templates (an address of another site written in them,
 * as quire:theme:lint finds them). The templates are read once per process.
 *
 * @internal
 */
final class ThemeLoads
{
    private const array HINTS = ['preconnect', 'dns-prefetch', 'prefetch', 'preload', 'modulepreload'];

    private ?bool $templatesLoadElsewhere = null;

    /**
     * @param list<array{id: string, path: string}> $chain The active theme and its parents
     */
    public function __construct(
        private readonly Csp $csp,
        private readonly HeadRenderer $head,
        #[Autowire(param: Themes::CHAIN_PARAMETER)]
        private readonly array $chain,
    ) {
    }

    /**
     * @param array<string, mixed> $context The page's template context, as quire_head() takes it
     */
    public function onlyFromThisSite(array $context): bool
    {
        if (!$this->csp->loadsOnlyFromThisSite()) {
            return false;
        }

        $head = $this->head->build($context);

        foreach ($head->keys() as $key) {
            $tag = $head->get($key);
            $attributes = \is_array($tag['attributes'] ?? null) ? $tag['attributes'] : [];
            $rel = \is_string($attributes['rel'] ?? null) ? strtolower($attributes['rel']) : '';
            $href = \is_string($attributes['href'] ?? null) ? $attributes['href'] : '';

            if ('link' === ($tag['element'] ?? null) && \in_array($rel, self::HINTS, true) && 1 === preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $href)) {
                return false;
            }
        }

        return !($this->templatesLoadElsewhere ??= $this->templatesLoadElsewhere());
    }

    private function templatesLoadElsewhere(): bool
    {
        foreach ($this->chain as $theme) {
            $directory = $theme['path'].'/templates';

            if (!is_dir($directory)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig') && [] !== TemplateUrls::find((string) file_get_contents($file->getPathname()))) {
                    return true;
                }
            }
        }

        return false;
    }
}
