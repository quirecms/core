<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Http\ErrorReference;
use Quire\Theme\Navigation\Navigation;
use Quire\Translation\Labels;
use Twig\DeprecatedCallableInfo;
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
            // A translated message with HTML parameters: the text escaped, Markup parameters as they are
            new TwigFilter('quire_trans_html', [TranslationRuntime::class, 'transHtml'], ['is_safe' => ['html']]),
            // A theme's text: translated, and marked as English where the page's language has no translation
            new TwigFilter('quire_trans', [TranslationRuntime::class, 'transHtml'], ['is_safe' => ['html']]),
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
            // The site's navigation: core's top-level pages, or a menus extension's
            new TwigFunction('quire_navigation', [Navigation::class, 'items']),
            // The site's setup steps the viewer may take, while some aren't done (the empty home page)
            new TwigFunction('quire_setup_steps', [ThemeRuntime::class, 'setupSteps']),
            // Whether the page loads nothing from other sites: the policy sent, its head, the theme's templates
            new TwigFunction('quire_loads_only_from_this_site', [ThemeRuntime::class, 'loadsOnlyFromThisSite'], ['needs_context' => true]),
            new TwigFunction('quire_head', [ThemeRuntime::class, 'head'], ['needs_context' => true, 'is_safe' => ['html']]),
            // A server error's reference and time, for the error page (null on other pages)
            new TwigFunction('quire_error_reference', [ErrorReference::class, 'current']),
            // The names people read for types, roles, abilities and settings, in the page's language (Quire\Translation\Labels)
            // The language a text shows in when the page's has none of it ("en"), for text a script writes
            new TwigFunction('quire_fallback_language', [TranslationRuntime::class, 'fallbackLanguage']),
            new TwigFunction('quire_type_label', [Labels::class, 'type']),
            new TwigFunction('quire_type_phrase', [Labels::class, 'phrase']),
            new TwigFunction('quire_role_label', [Labels::class, 'role']),
            new TwigFunction('quire_ability_label', [Labels::class, 'ability']),
            // The translation domain of what an owner declares: "core" → "quire", an extension's ID → itself
            new TwigFunction('quire_domain', Labels::domainOf(...)),
            new TwigFunction('quire_setting_label', [Labels::class, 'setting']),
            new TwigFunction('quire_setting_help', [Labels::class, 'settingHelp']),
            // The name before 0.3, when the head held only the SEO tags.
            new TwigFunction('quire_seo', [ThemeRuntime::class, 'head'], ['needs_context' => true, 'is_safe' => ['html'], 'deprecation_info' => new DeprecatedCallableInfo('quire/core', '0.3', 'quire_head')]),
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
