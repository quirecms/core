<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extension\ExtensionManifest;
use Quire\Theme\Exception\ThemeException;

/**
 * Resolves a theme and the themes it inherits from.
 *
 * @internal
 */
final class ThemeChain
{
    /**
     * @param array<string, ExtensionManifest> $themes            The installed themes, by ID
     * @param list<string>                     $enabledExtensions IDs of the extensions that run
     *
     * @return list<ExtensionManifest> The theme first, then its parent, the parent's parent, …
     *
     * @throws ThemeException when the theme or one of its parents isn't installed, they inherit in a
     *                        circle, or one of them requires an extension that doesn't run
     */
    public static function resolve(array $themes, string $id, array $enabledExtensions): array
    {
        $chain = [];
        $current = $id;

        while (null !== $current) {
            if (isset($chain[$current])) {
                throw new ThemeException(\sprintf('The themes %s inherit from each other in a circle.', implode(' → ', array_map(static fn (string $id): string => '"'.$id.'"', [...array_keys($chain), $current]))));
            }

            $theme = $themes[$current] ?? throw new ThemeException([] === $chain ? \sprintf('No theme with ID "%s" is installed.%s', $id, [] === $themes ? ' Install one with "composer require <package>".' : \sprintf(' Installed: "%s".', implode('", "', array_keys($themes)))) : \sprintf('The theme "%s" inherits from "%s", which isn\'t installed. Install it with Composer.', array_key_last($chain), $current));

            foreach ($theme->requires as $required) {
                if (!\in_array($required, $enabledExtensions, true)) {
                    throw new ThemeException(\sprintf('The theme "%s" requires the extension "%s". Enable it with "bin/console quire:extension:enable %2$s" first.', $theme->id, $required));
                }
            }

            $chain[$current] = $theme;
            $current = $theme->parent;
        }

        return array_values($chain);
    }
}
