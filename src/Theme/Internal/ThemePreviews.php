<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Assets;
use Quire\Extend\Settings;
use Quire\Extend\Views;
use Quire\Extension\ExtensionManifest;
use Quire\Theme\Exception\ThemeException;

/**
 * Finds out, while the application is built, which installed themes can be previewed without being
 * used: those that, with the themes they inherit from, add only templates, stylesheets and settings of
 * their own. Anything else (services, routes, migrations…) runs only once the site is built with the
 * theme, so those themes say why they can't be previewed.
 *
 * @internal
 */
final class ThemePreviews
{
    /** Extenders a previewed theme may use */
    private const array ALLOWED = [Assets::class, Settings::class, Views::class];

    /**
     * @param array<string, ExtensionManifest> $themes            Installed themes, by ID
     * @param list<string>                     $enabledExtensions
     *
     * @return array<string, array{chain: list<array{id: string, path: string}>, reason: ?string, stylesheets: list<string>, settings: array<string, mixed>, namespaces: array<string, string>}> By theme ID
     */
    public static function analyze(array $themes, array $enabledExtensions): array
    {
        $previews = [];

        foreach (array_keys($themes) as $id) {
            $preview = ['chain' => [], 'reason' => null, 'stylesheets' => [], 'settings' => [], 'namespaces' => []];

            try {
                $chain = ThemeChain::resolve($themes, $id, $enabledExtensions);
            } catch (ThemeException $e) {
                $previews[$id] = [...$preview, 'reason' => $e->getMessage()];

                continue;
            }

            $preview['chain'] = array_map(static fn (ExtensionManifest $theme): array => ['id' => $theme->id, 'path' => $theme->path], $chain);

            // Parents first, as they're built.
            foreach (array_reverse($chain) as $theme) {
                $file = $theme->path.\DIRECTORY_SEPARATOR.'extend.php';
                $extenders = is_file($file) ? self::extenders($file) : [];

                foreach ($extenders as $extender) {
                    $class = $extender::class;

                    if (!\in_array($class, self::ALLOWED, true)) {
                        $preview['reason'] ??= \sprintf('The theme "%s" adds more than templates, stylesheets and settings (%s), which only runs once the site uses it.', $theme->id, self::shortName($class));

                        continue;
                    }

                    if ($extender instanceof Assets) {
                        foreach ($extender->declared() as $asset) {
                            if ('site' !== $asset['group']) {
                                continue;
                            }

                            if ('stylesheet' !== $asset['type']) {
                                $preview['reason'] ??= \sprintf('The theme "%s" has scripts, which only run once the site uses it.', $theme->id);
                            } else {
                                $preview['stylesheets'][] = $asset['path'];
                            }
                        }
                    } elseif ($extender instanceof Settings) {
                        $preview['settings'] = [...$preview['settings'], ...$extender->defaults()];
                    } elseif ($extender instanceof Views) {
                        $views = $extender->declared();

                        if ($views['slots'] > 0 || $views['globals'] > 0) {
                            $preview['reason'] ??= \sprintf('The theme "%s" fills slots or adds variables to templates, which only runs once the site uses it.', $theme->id);
                        }

                        $preview['namespaces'] = [...$preview['namespaces'], ...$views['namespaces']];
                    }
                }
            }

            $previews[$id] = $preview;
        }

        return $previews;
    }

    /**
     * @return list<object>
     */
    private static function extenders(string $file): array
    {
        $extenders = (static fn (): mixed => require $file)();

        return \is_array($extenders) ? array_values(array_filter($extenders, \is_object(...))) : [];
    }

    /**
     * "Extend\\Routes" for Quire's extenders, the class's name for others.
     */
    private static function shortName(string $class): string
    {
        return str_starts_with($class, 'Quire\\Extend\\') ? substr($class, \strlen('Quire\\')) : $class;
    }
}
