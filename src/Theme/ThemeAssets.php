<?php

declare(strict_types=1);

namespace Quire\Theme;

use Quire\Theme\Exception\AssetNotFoundException;
use Symfony\Component\Asset\Packages;
use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * Finds assets by logical path, e.g. "acme-reviews/reviews.css" for assets/reviews.css of the extension
 * "acme-reviews", and gives their versioned URLs.
 *
 * "theme/<path>" is the active theme's file, or that of the closest theme it inherits from that has it,
 * so a child theme replaces a file of its parent by having one with the same path.
 *
 * @api
 */
final readonly class ThemeAssets
{
    private const string THEME_PREFIX = 'theme/';

    public function __construct(
        private AssetMapperInterface $mapper,
        private Packages $packages,
        private Themes $themes,
    ) {
    }

    /**
     * The asset's actual logical path ("theme/…" resolved), or null when there's no such asset.
     */
    public function find(string $path): ?string
    {
        if (!str_starts_with($path, self::THEME_PREFIX)) {
            return null === $this->mapper->getPublicPath($path) ? null : $path;
        }

        foreach ($this->themes->chain() as $id) {
            $candidate = $id.'/'.substr($path, \strlen(self::THEME_PREFIX));

            if (null !== $this->mapper->getPublicPath($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The asset's URL, with a version in the file name so browsers can cache it forever.
     *
     * @throws AssetNotFoundException
     */
    public function url(string $path): string
    {
        $found = $this->find($path) ?? throw new AssetNotFoundException(match (true) {
            !str_starts_with($path, self::THEME_PREFIX) => \sprintf('No asset "%s". Assets are files in the assets/ directory of the site, of an extension ("<id>/…") or of a theme ("<id>/…", or "theme/…" for the active theme).', $path), [] === $this->themes->chain() => \sprintf('No asset "%s": the site has no theme, so "theme/…" paths don\'t exist.', $path), default => \sprintf('No asset "%s": none of the themes %s has "assets/%s".', $path, '"'.implode('", "', $this->themes->chain()).'"', substr($path, \strlen(self::THEME_PREFIX))),
        });

        return $this->packages->getUrl($found);
    }
}
