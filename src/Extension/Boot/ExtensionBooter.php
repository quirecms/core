<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Quire\Database\MigrationOrder;
use Quire\Extend\Actions;
use Quire\Extend\Assets;
use Quire\Extend\Auth;
use Quire\Extend\ContentChecks;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderException;
use Quire\Extend\ExtenderInterface;
use Quire\Extend\Permissions;
use Quire\Extend\Routes;
use Quire\Extend\Views;
use Quire\Extension\DependencyResolver;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\ResolvedExtensions;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Internal\ThemeChain;
use Quire\Theme\Internal\ThemePreviews;
use Quire\Theme\Slots;
use Quire\Theme\ThemePreview;
use Quire\Theme\Themes;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs the extenders of the enabled extensions, then of the active theme and the themes it inherits
 * from, then of the site, while the container is being built.
 *
 * @internal
 */
final readonly class ExtensionBooter
{
    public const string BOOT_ORDER_PARAMETER = 'quire.extensions.boot_order';

    public const string FILES_PARAMETER = 'quire.extensions.extend_files';

    public function __construct(
        private InstalledExtensions $installed,
        private DependencyResolver $resolver = new DependencyResolver(),
    ) {
    }

    /**
     * Quire core's own extend.php, which registers core's content types, settings and migrations.
     */
    public static function coreExtendFile(): string
    {
        return \dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'extend.php';
    }

    /**
     * @param list<string> $enabledIds
     * @param string       $siteExtendFile The project's own extend.php, which runs last
     * @param string|null  $coreExtendFile Quire core's own extend.php, which runs first
     * @param string|null  $activeTheme    ID of the site's theme; when it can't be used, the site is built without one
     */
    public function boot(ContainerBuilder $container, array $enabledIds, string $siteExtendFile, ?string $coreExtendFile = null, ?string $activeTheme = null): ResolvedExtensions
    {
        $resolved = $this->resolver->resolve($this->installed->all(), $enabledIds);
        $bootOrder = $resolved->bootOrderIds();
        $files = [];

        $container->setParameter(Routes::PARAMETER, []);
        $container->setParameter(SettingsSchema::PARAMETER, []);
        $container->setParameter(MigrationOrder::PARAMETER, []);
        $container->setParameter(Views::PARAMETER, []);
        $container->setParameter(Slots::PARAMETER, []);
        $container->setParameter(Assets::PARAMETER, []);
        $container->setParameter(Assets::PACKAGES_PARAMETER, $this->packages());
        $container->setParameter(Permissions::PARAMETER, ['roles' => [], 'abilities' => [], 'rules' => []]);
        $container->setParameter(Auth::PARAMETER, []);
        $container->setParameter(ContentChecks::PARAMETER, []);
        $container->setParameter(Actions::PARAMETER, []);

        if (null !== $coreExtendFile) {
            $container->addResource(new FileResource($coreExtendFile));
            $this->run(new ExtenderContext(ExtenderContext::CORE, \dirname($coreExtendFile), $container, $bootOrder), $coreExtendFile);
        }

        foreach ($resolved->bootOrder as $manifest) {
            $file = $manifest->path.\DIRECTORY_SEPARATOR.'extend.php';
            $files[] = $file;

            if (is_file($file)) {
                $container->addResource(new FileResource($file));
                $this->run(new ExtenderContext($manifest->id, $manifest->path, $container, $bootOrder), $file);
            } else {
                $container->addResource(new FileExistenceResource($file));
            }
        }

        $themes = [];
        $themeProblem = null;

        // The default theme is optional: without it, the built-in templates are used, and that's no problem.
        if (Themes::DEFAULT === $activeTheme && !$this->installed->hasTheme(Themes::DEFAULT)) {
            $activeTheme = null;
        }

        if (null !== $activeTheme) {
            try {
                $themes = ThemeChain::resolve($this->installed->themes(), $activeTheme, $bootOrder);
            } catch (ThemeException $e) {
                $themeProblem = $e->getMessage();
            }
        }

        // Parents first, so a theme's extenders can build on its parent's.
        foreach (array_reverse($themes) as $theme) {
            $file = $theme->path.\DIRECTORY_SEPARATOR.'extend.php';
            $files[] = $file;
            $container->addResource(new FileExistenceResource($file));

            if (is_file($file)) {
                $container->addResource(new FileResource($file));
                $this->run(new ExtenderContext($theme->id, $theme->path, $container, $bootOrder, theme: true), $file);
            }
        }

        $this->addTranslations($container, $resolved, $themes);
        $container->setParameter(ThemePreview::PARAMETER, ThemePreviews::analyze($this->installed->themes(), $bootOrder));

        $container->setParameter(Themes::ACTIVE_PARAMETER, $activeTheme);
        $container->setParameter(Themes::CHAIN_PARAMETER, array_map(static fn (ExtensionManifest $theme): array => ['id' => $theme->id, 'path' => $theme->path], $themes));
        $container->setParameter(Themes::PROBLEM_PARAMETER, $themeProblem);

        $files[] = $siteExtendFile;
        $container->addResource(new FileExistenceResource($siteExtendFile));

        if (is_file($siteExtendFile)) {
            $container->addResource(new FileResource($siteExtendFile));
            $this->run(new ExtenderContext(ExtenderContext::SITE, \dirname($siteExtendFile), $container, $bootOrder), $siteExtendFile);
        }

        $container->setParameter(self::BOOT_ORDER_PARAMETER, $bootOrder);
        $container->setParameter(self::FILES_PARAMETER, $files);

        return $resolved;
    }

    /**
     * The npm packages of every installed extension and theme, enabled or not: the site downloads them
     * when it's installed or built, before it knows which ones it uses.
     *
     * @return list<array{name: string, version: string, owner: string}>
     */
    private function packages(): array
    {
        $packages = [];

        foreach ($this->installed->extensionsAndThemes() as $manifest) {
            foreach ($manifest->importmap as $name => $version) {
                $packages[] = ['name' => $name, 'version' => $version, 'owner' => $manifest->id];
            }
        }

        return $packages;
    }

    private function run(ExtenderContext $context, string $file): void
    {
        try {
            $extenders = (static fn (string $file): mixed => require $file)($file);
        } catch (ExtenderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExtenderException(\sprintf('[%s] Loading "%s" failed: %s', $context->id, $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (!is_iterable($extenders)) {
            throw new ExtenderException(\sprintf('[%s] "%s" must return a list of extenders, e.g. "return [new Extend\Routes()];", but it returned %s.', $context->id, $file, get_debug_type($extenders)));
        }

        $position = 0;

        foreach ($extenders as $extender) {
            ++$position;

            if (!$extender instanceof ExtenderInterface) {
                throw new ExtenderException(\sprintf('[%s] Item #%d returned by "%s" is %s; expected an extender such as new Quire\Extend\Routes().', $context->id, $position, $file, get_debug_type($extender)));
            }

            try {
                $extender->extend($context);
            } catch (ExtenderException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ExtenderException(\sprintf('[%s] %s (item #%d in "%s") failed: %s', $context->id, $extender::class, $position, $file, $e->getMessage()), $e->getCode(), previous: $e);
            }
        }
    }

    /**
     * Loads the translations/ directories of the extensions that run and of the themes in use. Later ones
     * win for the same message: extensions in boot order, then themes from the farthest parent to the
     * active theme; the site's own translations/ come after all of them.
     *
     * @param list<ExtensionManifest> $themes The active theme first
     */
    private function addTranslations(ContainerBuilder $container, ResolvedExtensions $resolved, array $themes): void
    {
        $paths = [];

        // First, so they never win: the other installed themes', for previewing them.
        $others = array_values(array_filter($this->installed->themes(), static fn (ExtensionManifest $theme): bool => !\in_array($theme, $themes, true)));

        foreach ([...$others, ...$resolved->bootOrder, ...array_reverse($themes)] as $manifest) {
            $directory = $manifest->path.\DIRECTORY_SEPARATOR.'translations';
            $container->addResource(new FileExistenceResource($directory));

            if (is_dir($directory)) {
                $paths[] = $directory;
            }
        }

        if ([] !== $paths) {
            $container->prependExtensionConfig('framework', ['translator' => ['paths' => $paths]]);
        }
    }
}
