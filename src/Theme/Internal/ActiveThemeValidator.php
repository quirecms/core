<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Settings\Settings;
use Quire\Settings\SettingValidatorInterface;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Themes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Accepts only themes that can be used as the "theme.active" setting, however it's changed.
 *
 * @internal
 */
final readonly class ActiveThemeValidator implements SettingValidatorInterface
{
    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
        #[Autowire(param: Themes::PINNED_PARAMETER)]
        private bool $pinned,
    ) {
    }

    public function validate(string $key, mixed $value, Settings $settings): ?string
    {
        if (Themes::SETTING !== $key) {
            return null;
        }

        if ($this->pinned) {
            return 'The theme is pinned in configuration ("quire.extensions.theme"), so this setting isn\'t used.';
        }

        if (!\is_string($value)) {
            return null;
        }

        try {
            ThemeChain::resolve($this->installed->themes(), $value, $this->extensions->resolve()->bootOrderIds());
        } catch (ThemeException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
