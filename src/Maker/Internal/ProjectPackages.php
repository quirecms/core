<?php

declare(strict_types=1);

namespace Quire\Maker\Internal;

/**
 * The Composer packages installed in the project, as its installed.json lists them (the parameter
 * quire.composer.installed_json).
 *
 * @internal
 */
final class ProjectPackages
{
    /**
     * Whether the project has the package: new sites from the skeleton don't have quire/testing, which
     * the tests of a theme or an extension the makers create need.
     */
    public static function has(string $installedJson, string $package): bool
    {
        $json = '' === $installedJson ? false : @file_get_contents($installedJson);
        $installed = false === $json ? null : json_decode($json, true);
        $packages = \is_array($installed) ? ($installed['packages'] ?? $installed) : [];

        return array_any(\is_array($packages) ? $packages : [], static fn ($entry): bool => \is_array($entry) && ($entry['name'] ?? null) === $package);
    }
}
