<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extension\Boot\ExtensionBooter;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Slots;
use Twig\Environment;
use Twig\Error\Error;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\Source;

/**
 * Checks a theme without rendering it: its templates compile, together they render every standard slot,
 * and the assets they link exist.
 *
 * The templates checked are those the site would use with the theme: the theme's, its parents' where it
 * has none, and Quire's built-in ones where no theme has one.
 *
 * @internal
 */
final readonly class ThemeLinter
{
    public function __construct(
        private Environment $twig,
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * @return array{templates: int, errors: list<string>, warnings: list<string>}
     */
    public function lint(string $id): array
    {
        try {
            $chain = ThemeChain::resolve($this->installed->themes(), $id, $this->extensions->resolve()->bootOrderIds());
        } catch (ThemeException $e) {
            return ['templates' => 0, 'errors' => [$e->getMessage()], 'warnings' => []];
        }

        $errors = [];
        $slots = [];
        $seo = false;
        $templates = $this->templates($chain);
        // Every template the site may render, then those they extend, include or embed, e.g. a parent
        // theme's version through "@<theme-id>/…".
        $queue = $templates;
        $checked = [];

        while ([] !== $queue) {
            $name = array_key_first($queue);
            $file = $queue[$name];
            unset($queue[$name]);

            if (isset($checked[$file])) {
                continue;
            }

            $checked[$file] = true;

            try {
                $module = $this->twig->parse($this->twig->tokenize(new Source((string) file_get_contents($file), $name, $file)));
            } catch (Error $e) {
                $errors[] = \sprintf('%s: %s', $this->relative($file, $chain), $e->getMessage());

                continue;
            }

            foreach ($this->calls($module) as [$function, $argument, $line]) {
                if ('quire_seo' === $function) {
                    $seo = true;
                } elseif ('quire_slot' === $function && null !== $argument) {
                    $slots[$argument] = true;
                } elseif ('quire_asset' === $function && null !== $argument && !$this->assetExists($argument, $chain)) {
                    $errors[] = \sprintf('%s line %d: quire_asset(\'%s\') links to a file that doesn\'t exist.', $this->relative($file, $chain), $line, $argument);
                }
            }

            foreach ($this->referencedTemplates($module) as $referenced) {
                $referencedFile = $this->resolve($referenced, $chain, $templates);

                if (null !== $referencedFile && !isset($checked[$referencedFile])) {
                    $queue[$referenced] = $referencedFile;
                }
            }
        }

        $missing = array_values(array_diff(array_keys(Slots::STANDARD), array_keys($slots)));

        if ([] !== $missing) {
            $errors[] = \sprintf('The theme doesn\'t render the standard slots %s, so extensions can\'t add their parts there. Add {{ quire_slot(\'…\') }} where they belong; see the list in docs/themes.md.', implode(', ', $missing));
        }

        $warnings = $seo ? [] : ['No template calls quire_seo(), so pages have no description, canonical URL or social tags. Add {{ quire_seo() }} to the <head> of the layout.'];

        // Cards are also shown alone, as previews in the admin, where there's no page request.
        foreach ($templates as $name => $file) {
            if (1 === preg_match('~^content/(?:[^/]+/)?_card\.html\.twig$~', $name) && 1 === preg_match('/\bapp\.(request|session|user|current_route)/', (string) file_get_contents($file))) {
                $errors[] = \sprintf('%s uses the request (app.…), but cards are also shown alone, as previews. Use only item, type, site and theme.', $this->relative($file, $chain));
            }
        }

        [$screenshotErrors, $screenshotWarnings] = $this->screenshot($chain[0]);

        return [
            'templates' => \count($templates),
            'errors' => [...$errors, ...$screenshotErrors],
            'warnings' => [...$warnings, ...$screenshotWarnings],
        ];
    }

    /**
     * The theme's own screenshot, which the admin shows when choosing a theme.
     *
     * @return array{0: list<string>, 1: list<string>} Errors and warnings
     */
    private function screenshot(ExtensionManifest $theme): array
    {
        $file = $theme->screenshotPath();

        if (null === $file) {
            return [[], ['The theme has no screenshot, so people choosing a theme can\'t see it. Add a 1200 × 900 picture of a page, and name it in composer.json as "extra.quire.theme.screenshot".']];
        }

        $size = is_file($file) ? @getimagesize($file) : false;

        if (false === $size) {
            return [[\sprintf('The screenshot "%s" is missing, or isn\'t an image.', $theme->screenshot)], []];
        }

        return [[], [1200, 900] === [$size[0], $size[1]] ? [] : [\sprintf('The screenshot is %d × %d; 1200 × 900 shows best.', $size[0], $size[1])]];
    }

    /**
     * The templates the site would use with the theme, by name.
     *
     * @param list<ExtensionManifest> $chain The theme first
     *
     * @return array<string, string> Template name => file
     */
    private function templates(array $chain): array
    {
        $directories = [
            ...array_map(static fn (ExtensionManifest $theme): string => $theme->path.'/templates', $chain),
            \dirname(ExtensionBooter::coreExtendFile()).'/templates',
        ];
        $templates = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                    $templates[substr($file->getPathname(), \strlen($directory) + 1)] ??= $file->getPathname();
                }
            }
        }

        ksort($templates);

        return $templates;
    }

    /**
     * Calls of Quire's template functions, with their first argument when it's a constant.
     *
     * @return list<array{string, ?string, int}> Function, first argument, line
     */
    private function calls(Node $node): array
    {
        $calls = [];

        if ($node instanceof FunctionExpression) {
            $name = $node->getAttribute('name');
            $arguments = $node->getNode('arguments');
            $first = $arguments->hasNode('0') ? $arguments->getNode('0') : null;
            $calls[] = [\is_string($name) ? $name : '', $first instanceof ConstantExpression && \is_string($first->getAttribute('value')) ? $first->getAttribute('value') : null, $node->getTemplateLine()];
        }

        foreach ($node as $child) {
            $calls = [...$calls, ...$this->calls($child)];
        }

        return $calls;
    }

    /**
     * Names of templates the template refers to, e.g. in {% extends %} or {{ include() }}.
     *
     * @return list<string>
     */
    private function referencedTemplates(Node $node): array
    {
        $names = [];

        if ($node instanceof ConstantExpression && \is_string($node->getAttribute('value')) && str_ends_with($node->getAttribute('value'), '.twig')) {
            $names[] = $node->getAttribute('value');
        }

        foreach ($node as $child) {
            $names = [...$names, ...$this->referencedTemplates($child)];
        }

        return $names;
    }

    /**
     * @param list<ExtensionManifest> $chain
     * @param array<string, string>   $templates The templates in effect, by name
     */
    private function resolve(string $name, array $chain, array $templates): ?string
    {
        if (!str_starts_with($name, '@')) {
            return $templates[$name] ?? null;
        }

        [$namespace, $path] = explode('/', substr($name, 1), 2) + [1 => ''];

        if ('Quire' === $namespace) {
            $file = \dirname(ExtensionBooter::coreExtendFile()).'/templates/'.$path;

            return is_file($file) ? $file : null;
        }

        foreach ($chain as $theme) {
            if ($theme->id === $namespace && is_file($theme->path.'/templates/'.$path)) {
                return $theme->path.'/templates/'.$path;
            }
        }

        return null;
    }

    /**
     * @param list<ExtensionManifest> $chain
     */
    private function assetExists(string $path, array $chain): bool
    {
        [$owner, $rest] = str_contains($path, '/') ? explode('/', $path, 2) : ['', $path];

        if ('theme' === $owner) {
            $packages = $chain;
        } elseif (isset($this->installed->extensionsAndThemes()[$owner])) {
            $packages = [$this->installed->extensionsAndThemes()[$owner]];
        } else {
            // The site's own assets/ can be mapped anywhere, so its paths can't be checked here.
            return true;
        }

        return array_any($packages, static fn (ExtensionManifest $package): bool => is_file($package->path.'/assets/'.$rest));
    }

    /**
     * @param list<ExtensionManifest> $chain
     */
    private function relative(string $file, array $chain): string
    {
        foreach ($chain as $theme) {
            if (str_starts_with($file, $theme->path.'/')) {
                return $theme->id.': '.substr($file, \strlen($theme->path) + 1);
            }
        }

        return 'Quire: '.basename(\dirname($file)).'/'.basename($file);
    }
}
