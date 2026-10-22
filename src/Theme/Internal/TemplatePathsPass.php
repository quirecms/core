<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Views;
use Quire\Theme\Themes;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Sets the order in which Twig looks for templates, first match wins:
 *
 *  1. the site's templates/ directory
 *  2. the active theme's templates/
 *  3. the templates/ of the themes it inherits from, closest first
 *  4. for "@namespace/…" templates of extensions: the extension's own directory, after the overrides in
 *     templates/extensions/<namespace>/ of the site and the themes (in the same order as above)
 *  5. Quire's built-in templates, which are also available as "@Quire/…"
 *
 * Each theme's templates are also available as "@<theme-id>/…", so a theme or the site can extend a
 * template it overrides, and each extension's as "@!<namespace>/…", without the overrides.
 *
 * Themes override templates of Symfony bundles like the site does, in templates/bundles/<Name>Bundle/,
 * e.g. the error pages in templates/bundles/TwigBundle/Exception/error404.html.twig.
 *
 * TwigBundle adds configured paths before the site's templates/, so themes can't use them: this pass
 * appends to what TwigBundle set up.
 *
 * @internal
 */
final class TemplatePathsPass implements CompilerPassInterface
{
    private const string LOADER = 'twig.loader.native_filesystem';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::LOADER)) {
            return;
        }

        $loader = $container->getDefinition(self::LOADER);
        $siteDirectory = $container->getParameterBag()->resolveValue($container->getParameter('twig.default_path'));
        \assert(\is_string($siteDirectory));

        /** @var list<array{id: string, path: string}> $themes */
        $themes = $container->getParameter(Themes::CHAIN_PARAMETER);
        /** @var list<array{namespace: string, directory: string, owner: string}> $namespaces */
        $namespaces = $container->getParameter(Views::PARAMETER);
        $themeDirectories = [];

        foreach ($themes as $theme) {
            $directory = $theme['path'].\DIRECTORY_SEPARATOR.'templates';
            $container->addResource(new FileExistenceResource($directory));

            if (!is_dir($directory)) {
                continue;
            }

            $themeDirectories[] = $directory;
            $loader->addMethodCall('addPath', [$directory]);

            // Unless an extension uses the name: theme IDs usually have a "-", namespaces can't.
            if (!\in_array($theme['id'], array_column($namespaces, 'namespace'), true)) {
                $loader->addMethodCall('addPath', [$directory, $theme['id']]);
            }
        }

        $this->addBundleOverrides($loader, $siteDirectory, $themeDirectories);

        // Last: Quire's minimal built-in templates, e.g. layout.html.twig, for what no theme provides.
        $loader->addMethodCall('addPath', [\dirname(__DIR__, 3).\DIRECTORY_SEPARATOR.'templates']);

        foreach ($namespaces as $registered) {
            foreach ([$siteDirectory, ...$themeDirectories] as $root) {
                $override = $root.\DIRECTORY_SEPARATOR.'extensions'.\DIRECTORY_SEPARATOR.$registered['namespace'];
                $container->addResource(new FileExistenceResource($override));

                if (is_dir($override)) {
                    $loader->addMethodCall('addPath', [$override, $registered['namespace']]);
                }
            }

            $loader->addMethodCall('addPath', [$registered['directory'], $registered['namespace']]);
            $loader->addMethodCall('addPath', [$registered['directory'], '!'.$registered['namespace']]);
        }
    }

    /**
     * Puts each theme's templates/bundles/<Name>Bundle/ after the site's and before the bundle's own.
     *
     * @param list<string> $themeDirectories The active theme's first
     */
    private function addBundleOverrides(Definition $loader, string $siteDirectory, array $themeDirectories): void
    {
        /** @var list<array{0: string, 1: list<mixed>}> $calls */
        $calls = $loader->getMethodCalls();

        foreach ($themeDirectories as $themeDirectory) {
            $overrides = glob($themeDirectory.'/bundles/*Bundle', \GLOB_ONLYDIR);

            foreach (false === $overrides ? [] : $overrides as $override) {
                $namespace = substr(basename($override), 0, -\strlen('Bundle'));
                $siteOverride = $siteDirectory.'/bundles/'.basename($override);

                $position = \count($calls);

                foreach ($calls as $index => [$method, $arguments]) {
                    if ('addPath' === $method && ($arguments[1] ?? null) === $namespace && $arguments[0] !== $siteOverride) {
                        $position = $index;

                        break;
                    }
                }

                // Before the bundle's own templates; TwigBundle has none, its error pages are only overrides.
                array_splice($calls, $position, 0, [['addPath', [$override, $namespace]]]);
            }
        }

        $loader->setMethodCalls($calls);
    }
}
