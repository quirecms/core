<?php

declare(strict_types=1);

namespace Quire\Extend;

/**
 * Adds stylesheets and scripts to the site's pages.
 *
 * Files in an extension's or theme's assets/ directory are available to AssetMapper as "<id>/<path>",
 * e.g. assets/reviews.css of "acme-reviews" is "acme-reviews/reviews.css". A theme can use "theme/<path>"
 * for its own files: it means the active theme's file, or its parent's when the active theme doesn't
 * have it.
 *
 *     new Extend\Assets()
 *         ->stylesheet('acme-reviews/reviews.css')
 *         ->script('acme-reviews/reviews.js')       // a JavaScript module
 *         ->entrypoint('app');                      // an entrypoint of the site's importmap.php
 *
 * Quire adds them to the "head" slot of every page of the group, in the order the extensions boot, then
 * themes (parents first), then the site, so later stylesheets can override earlier ones.
 *
 * @api
 */
final class Assets implements ExtenderInterface
{
    /** @internal Parameter listing the assets as {type, path, group, owner} */
    public const string PARAMETER = 'quire.assets';

    /** @internal Parameter listing the packages of "extra.quire.importmap" as {name, version, owner} */
    public const string PACKAGES_PARAMETER = 'quire.assets.packages';

    /** Pages of the site, and of the admin interface */
    public const array GROUPS = ['site', 'admin'];

    private const string PATH_PATTERN = '#^[a-zA-Z0-9][a-zA-Z0-9._-]*(?:/[a-zA-Z0-9._-]+)*$#';

    private const string ENTRYPOINT_PATTERN = '/^[a-zA-Z0-9_\/.-]+$/';

    /** @var list<array{type: string, path: string, group: string}> */
    private array $assets = [];

    /**
     * A stylesheet, as a logical path such as "acme-reviews/reviews.css".
     */
    public function stylesheet(string $path, string $group = 'site'): self
    {
        $this->assets[] = ['type' => 'stylesheet', 'path' => $path, 'group' => $group];

        return $this;
    }

    /**
     * A JavaScript module, as a logical path such as "acme-reviews/reviews.js". It can import other files
     * with relative paths ("./chart.js"), packages of the site's importmap, and the npm packages that
     * the extension or theme lists in "extra.quire.importmap" of its composer.json:
     *
     *     "extra": {"quire": {"importmap": {"@hotwired/turbo": "8.0.23"}}}
     */
    public function script(string $path, string $group = 'site'): self
    {
        $this->assets[] = ['type' => 'script', 'path' => $path, 'group' => $group];

        return $this;
    }

    /**
     * An entrypoint of the site's importmap.php, e.g. "app". Mostly for the site's own extend.php.
     */
    public function entrypoint(string $name, string $group = 'site'): self
    {
        $this->assets[] = ['type' => 'entrypoint', 'path' => $name, 'group' => $group];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->assets as $asset) {
            $where = \sprintf('Extend\Assets::%s("%s")', $asset['type'], $asset['path']);

            if (!\in_array($asset['group'], self::GROUPS, true)) {
                throw $context->error($where, \sprintf('Unknown group "%s". Use one of: %s.', $asset['group'], implode(', ', self::GROUPS)));
            }

            if ('entrypoint' === $asset['type']) {
                if (1 !== preg_match(self::ENTRYPOINT_PATTERN, $asset['path'])) {
                    throw $context->error($where, 'Use the name of an entrypoint in importmap.php, e.g. "app".');
                }
            } else {
                $this->checkPath($context, $where, $asset['type'], $asset['path']);
            }

            $context->appendToParameter(self::PARAMETER, [...$asset, 'owner' => $context->id]);
        }
    }

    private function checkPath(ExtenderContext $context, string $where, string $type, string $path): void
    {
        if (1 !== preg_match(self::PATH_PATTERN, $path)) {
            throw $context->error($where, \sprintf('Use a logical path such as "%s/%s": the ID of the extension or theme (or "theme"), then the path inside its assets/ directory. The site\'s own files have no prefix, e.g. "styles/app.css".', $context->id, 'stylesheet' === $type ? 'styles.css' : 'script.js'));
        }

        $extensions = 'stylesheet' === $type ? ['css'] : ['js', 'mjs'];

        if (!\in_array(pathinfo($path, \PATHINFO_EXTENSION), $extensions, true)) {
            throw $context->error($where, \sprintf('%s must be .%s files.', 'stylesheet' === $type ? 'Stylesheets' : 'Scripts', implode(' or .', $extensions)));
        }

        // The caller's own files can be checked now; others may come from packages loaded later.
        $own = $context->id.'/';

        if (str_starts_with($path, $own) && !is_file($context->path.\DIRECTORY_SEPARATOR.'assets'.\DIRECTORY_SEPARATOR.substr($path, \strlen($own)))) {
            throw $context->error($where, \sprintf('The file "%s" doesn\'t exist.', $context->path.\DIRECTORY_SEPARATOR.'assets'.\DIRECTORY_SEPARATOR.substr($path, \strlen($own))));
        }
    }
}
