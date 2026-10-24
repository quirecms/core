<?php

declare(strict_types=1);

namespace Quire\Theme;

use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;
use Quire\Theme\Exception\ThemeException;

/**
 * Changes the site's theme and rebuilds the application for it: what quire:theme:use does, for admin
 * interfaces too. Other servers notice the change and rebuild by themselves.
 *
 * @api
 */
final readonly class ThemeSwitcher
{
    public function __construct(
        private Themes $themes,
        private AppRebuilderInterface $rebuilder,
    ) {
    }

    /**
     * @param string|null            $id     The theme's ID; null for Quire's built-in templates
     * @param \Closure(string): void $output What the rebuild prints
     *
     * @return bool Whether anything changed: false when the site uses the theme already
     *
     * @throws ThemeException         when the theme can't be used, e.g. it isn't installed
     * @throws RebuildFailedException after the previous theme is back in use
     */
    public function use(?string $id, \Closure $output): bool
    {
        $previous = $this->themes->selected();

        if ($id === $previous && null === $this->themes->problem()) {
            return false;
        }

        $this->themes->use($id);
        $output("Rebuilding the application…\n");

        try {
            $this->rebuilder->rebuild(true, $output);
        } catch (RebuildFailedException $e) {
            // Don't leave a theme set whose extenders or migrations failed.
            try {
                $this->themes->use($previous);
            } catch (ThemeException) {
                // E.g. the default theme, which isn't installed: setting it again is refused.
                Themes::DEFAULT === $previous ? $this->themes->useDefault() : $this->themes->use(null);
            }

            $this->rebuilder->rebuild(false, $output);

            throw new RebuildFailedException(\sprintf('%s The previous theme is back in use.', $e->getMessage()), $e->getCode(), previous: $e);
        }

        return true;
    }
}
