<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The template API of themes: slots, the `site` and `theme` variables, settings and extensions.
 *
 * @internal
 */
final class ThemeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteView $site,
        private readonly ThemeView $theme,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('quire_date', [ThemeRuntime::class, 'date']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('quire_slot', [ThemeRuntime::class, 'slot'], ['is_safe' => ['html']]),
            new TwigFunction('quire_slot_filled', [ThemeRuntime::class, 'slotFilled']),
            new TwigFunction('quire_setting', [ThemeRuntime::class, 'setting']),
            new TwigFunction('quire_extension_enabled', [ThemeRuntime::class, 'extensionEnabled']),
            new TwigFunction('quire_asset', [ThemeRuntime::class, 'asset']),
            new TwigFunction('quire_seo', [ThemeRuntime::class, 'seo'], ['needs_context' => true, 'is_safe' => ['html']]),
        ];
    }

    /**
     * Objects whose values are read when used: Twig keeps globals for the life of a PHP worker.
     */
    public function getGlobals(): array
    {
        return ['site' => $this->site, 'theme' => $this->theme];
    }
}
