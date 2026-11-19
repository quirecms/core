<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Extension\Boot\ExtensionBooter;
use Quire\Head\Internal\HeadRenderer;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Setup\SetupSteps;
use Quire\Theme\Exception\AssetNotFoundException;
use Quire\Theme\Internal\ThemeLoads;
use Quire\Theme\Slots;
use Quire\Theme\ThemeAssets;
use Quire\Theme\ThemePreview;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Error\RuntimeError;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * @internal
 */
final readonly class ThemeRuntime implements RuntimeExtensionInterface
{
    /**
     * @param list<string> $extensions IDs of the extensions that run
     */
    public function __construct(
        private Slots $slots,
        private ThemeAssets $assets,
        private HeadRenderer $head,
        private Settings $settings,
        private SettingsSchema $schema,
        #[Autowire(param: ExtensionBooter::BOOT_ORDER_PARAMETER)]
        private array $extensions,
        private RequestStack $requests,
        private ThemePreview $preview,
        private ThemeLoads $loads,
        private SetupSteps $setup,
    ) {
    }

    /**
     * `{{ quire_slot('content.after_body', { content }) }}`.
     *
     * @param array<string, mixed> $context
     */
    public function slot(string $name, array $context = []): string
    {
        return $this->slots->render($name, $context);
    }

    /**
     * `{% if quire_slot_filled('sidebar') %}<aside>{{ quire_slot('sidebar') }}</aside>{% endif %}`.
     */
    public function slotFilled(string $name): bool
    {
        return $this->slots->has($name);
    }

    /**
     * `{{ quire_setting('site.description') }}`: only settings exposed to templates, so private ones such
     * as API keys can't end up in a page.
     */
    public function setting(string $key): mixed
    {
        // A previewed theme's own settings aren't declared: at their defaults.
        if (\array_key_exists($key, $this->preview->settings())) {
            return $this->preview->settings()[$key];
        }

        if (!$this->schema->has($key) || !$this->schema->get($key)->exposed) {
            throw new RuntimeError(\sprintf('The setting "%s" isn\'t available to templates. Settings are, when they\'re declared with "exposed: true", or by a theme.', $key));
        }

        return $this->settings->get($key);
    }

    /**
     * `{{ quire_asset('theme/logo.svg') }}`: the versioned URL of an asset; "theme/…" is the active theme's
     * file, or its parent's.
     */
    public function asset(string $path): string
    {
        try {
            return $this->assets->url($path);
        } catch (AssetNotFoundException $e) {
            throw new RuntimeError($e->getMessage(), previous: $e);
        }
    }

    /**
     * `{{ quire_head() }}` in a layout's <head>: the page's meta tags, links and structured data, from
     * the head contributors (see Extend\Head), each once: the description, canonical URL, Open Graph
     * and Twitter tags, and what extensions add.
     *
     * @param array<string, mixed> $context
     */
    public function head(array $context): string
    {
        return $this->head->render($context);
    }

    /**
     * `{% set setup = quire_setup_steps() %}{% if setup %}…{{ setup.done }} of {{ setup.steps|length }}…{% endif %}`:
     * the site's setup steps the viewer may take, while some aren't done; null for visitors and once
     * all are. A page that shows them is the viewer's alone (private, never stored).
     *
     * @return array{steps: list<\Quire\Setup\SetupStep>, done: int}|null
     */
    public function setupSteps(): ?array
    {
        return $this->setup->pending();
    }

    /**
     * `{% if quire_loads_only_from_this_site() %}This site loads nothing from other companies.{% endif %}`:
     * decided by the very policy the page's header sends, its head and the theme's own templates, so
     * the sentence can't drift from what browsers do.
     *
     * @param array<string, mixed> $context
     */
    public function loadsOnlyFromThisSite(array $context): bool
    {
        return $this->loads->onlyFromThisSite($context);
    }

    /**
     * The parts of a date a design asks for, as ICU skeletons: each language orders and writes them its own way.
     */
    private const array PARTS = [
        'month-year' => 'yMMMM',      // November 2026, November 2026, ноември 2026
        'month' => 'MMM',             // Nov
        'day-month' => 'MMMMd',       // November 13, 13. November
        'day-month-short' => 'MMMd',  // Nov 13
        'weekday-day' => 'EEEMMMd',   // Wed, Nov 11; Mi., 11. Nov.
        'day' => 'dd',                // 03, 11
    ];

    /**
     * `{{ post.publishedAt|quire_date }}`: a date written the way the page's language writes it, e.g.
     * "October 22, 2026" in English ("en" is ICU's American English) and "22. Oktober 2026" in German.
     *
     * Styles: "long" (default), "medium", "short" or "full"; with the time, "long-time" ("November 12,
     * 2026 at 6:04 PM"); or parts of it, "month-year", "month", "day-month", "day-month-short",
     * "weekday-day" and "day", in the language's own order.
     */
    public function date(?\DateTimeInterface $date, string $style = 'long'): string
    {
        if (!$date instanceof \DateTimeInterface) {
            return '';
        }

        $styles = ['full' => \IntlDateFormatter::FULL, 'long' => \IntlDateFormatter::LONG, 'medium' => \IntlDateFormatter::MEDIUM, 'short' => \IntlDateFormatter::SHORT];
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? $this->settings->getString('site.locale');

        if (isset(self::PARTS[$style])) {
            $pattern = new \IntlDatePatternGenerator($locale)->getBestPattern(self::PARTS[$style]);
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, date_default_timezone_get(), null, false === $pattern ? null : $pattern);

            return (string) $formatter->format($date);
        }

        if ('long-time' === $style) {
            $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT, date_default_timezone_get());

            return (string) $formatter->format($date);
        }

        $formatter = new \IntlDateFormatter($locale, $styles[$style] ?? throw new RuntimeError(\sprintf('Unknown date style "%s". Use "full", "long", "medium", "short", "long-time" or one of "%s".', $style, implode('", "', array_keys(self::PARTS)))), \IntlDateFormatter::NONE, date_default_timezone_get());

        return (string) $formatter->format($date);
    }

    /**
     * `{% if quire_extension_enabled('acme-reviews') %}…{% endif %}`.
     */
    public function extensionEnabled(string $id): bool
    {
        return \in_array($id, $this->extensions, true);
    }
}
