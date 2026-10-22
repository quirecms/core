<?php

declare(strict_types=1);

namespace Quire\Theme;

use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Internal\ThemeChain;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The installed themes and the site's active theme.
 *
 * The active theme is the "theme.active" setting, or "quire.extensions.theme" in the configuration when
 * extensions are pinned. It's part of how the application is built (its templates and its extend.php),
 * so changing it rebuilds the application, on every server.
 *
 * @api
 */
final readonly class Themes
{
    /** The setting that holds the active theme's ID */
    public const string SETTING = 'theme.active';

    /**
     * The theme of a site that hasn't chosen one. When it isn't installed, e.g. in an application that
     * Quire was added to, the site uses Quire's built-in templates instead.
     */
    public const string DEFAULT = 'quire-theme-default';

    /** @internal The ID of the theme the application was built for (null for none) */
    public const string ACTIVE_PARAMETER = 'quire.theme.active';

    /** @internal The active theme and its parents, as {id, path}, the active theme first */
    public const string CHAIN_PARAMETER = 'quire.theme.chain';

    /** @internal Why the active theme couldn't be used when the application was built, or null */
    public const string PROBLEM_PARAMETER = 'quire.theme.problem';

    /** @internal Whether the theme is fixed in the configuration */
    public const string PINNED_PARAMETER = 'quire.theme.pinned';

    /**
     * @param list<array{id: string, path: string}> $chain
     */
    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
        private Settings $settings,
        #[Autowire(param: self::ACTIVE_PARAMETER)]
        private ?string $configured,
        #[Autowire(param: self::CHAIN_PARAMETER)]
        private array $chain,
        #[Autowire(param: self::PROBLEM_PARAMETER)]
        private ?string $problem,
        #[Autowire(param: self::PINNED_PARAMETER)]
        private bool $pinned,
    ) {
    }

    /**
     * @return array<string, ExtensionManifest> The installed themes, by ID
     */
    public function installed(): array
    {
        return $this->installed->themes();
    }

    /**
     * The theme the site is built with; null when it uses Quire's built-in templates, either because no
     * theme is set or because the theme that is set can't be used (see {@see problem()}).
     */
    public function active(): ?ExtensionManifest
    {
        $id = $this->chain[0]['id'] ?? null;

        return null === $id ? null : ($this->installed->themes()[$id] ?? null);
    }

    /**
     * The ID of the theme that is set, even when it can't be used: the setting, or the configuration when
     * the theme is pinned. It differs from {@see active()} until the application is rebuilt after a change.
     */
    public function selected(): ?string
    {
        if ($this->pinned) {
            return $this->configured;
        }

        $id = $this->settings->get(self::SETTING);

        return \is_string($id) ? $id : null;
    }

    /**
     * IDs of the active theme and the themes it inherits from, the active theme first.
     *
     * @return list<string>
     */
    public function chain(): array
    {
        return array_column($this->chain, 'id');
    }

    /**
     * Why the theme that is set couldn't be used when the application was built, e.g. it was uninstalled.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * Why the theme can't be changed at runtime, or null when it can.
     */
    public function readOnlyReason(): ?string
    {
        return $this->pinned ? 'The theme is pinned in configuration ("quire.extensions.theme", because extensions are pinned). Change it in config/packages/quire.yaml, then redeploy.' : null;
    }

    /**
     * Checks that a theme can be used: it and its parents are installed, and the extensions they require
     * are enabled.
     *
     * @return list<ExtensionManifest> The theme first, then its parents
     *
     * @throws ThemeException
     */
    public function check(string $id): array
    {
        return ThemeChain::resolve($this->installed->themes(), $id, $this->extensions->resolve()->bootOrderIds());
    }

    /**
     * Goes back to the default theme, or to the built-in templates when it isn't installed.
     */
    public function useDefault(): void
    {
        $reason = $this->readOnlyReason();

        if (null !== $reason) {
            throw new ThemeException($reason);
        }

        $this->settings->restoreDefault(self::SETTING);
    }

    /**
     * Makes a theme the site's theme, or none with null. The application has to be rebuilt afterwards;
     * other servers do that by themselves.
     *
     * @throws ThemeException
     */
    public function use(?string $id): void
    {
        $reason = $this->readOnlyReason();

        if (null !== $reason) {
            throw new ThemeException($reason);
        }

        try {
            $this->settings->set(self::SETTING, $id);
        } catch (InvalidSettingValueException $e) {
            throw new ThemeException($e->getMessage(), $e->getCode(), previous: $e);
        }
    }
}
