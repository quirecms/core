<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Extension\Boot\ExtensionBooter;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Exception\AssetNotFoundException;
use Quire\Theme\Slots;
use Quire\Theme\ThemeAssets;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private Settings $settings,
        private SettingsSchema $schema,
        #[Autowire(param: ExtensionBooter::BOOT_ORDER_PARAMETER)]
        private array $extensions,
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
     * `{% if quire_extension_enabled('acme-reviews') %}…{% endif %}`.
     */
    public function extensionEnabled(string $id): bool
    {
        return \in_array($id, $this->extensions, true);
    }
}
