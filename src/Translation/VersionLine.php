<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * The version line of a package, which a language artifact is made for: "0.5" before 1.0 (a minor
 * version may change texts), the major version from 1.0 on ("2").
 *
 * @api
 */
final class VersionLine
{
    private function __construct()
    {
    }

    /**
     * The line of a version: "0.5.3" is "0.5", "2.1.0" is "2", "0.5.x-dev" is "0.5"; null for a branch
     * without a number ("dev-main").
     */
    public static function of(string $version): ?string
    {
        if (1 !== preg_match('/^v?(?<major>\d+)(?:\.(?<minor>\d+|x))?/i', $version, $m)) {
            return null;
        }

        $major = (int) $m['major'];

        if ($major > 0) {
            return (string) $major;
        }

        return isset($m['minor']) && ctype_digit($m['minor']) ? '0.'.(int) $m['minor'] : null;
    }

    /**
     * The line of an installed package (an entry of Composer's installed.json): a branch reads its alias
     * ("dev-main" with "extra.branch-alias": {"dev-main": "0.5.x-dev"} is "0.5"); null without one.
     *
     * @param array<mixed> $package
     */
    public static function ofPackage(array $package): ?string
    {
        $version = \is_string($package['pretty_version'] ?? null) ? $package['pretty_version'] : (\is_string($package['version'] ?? null) ? $package['version'] : '');

        if (str_starts_with($version, 'dev-')) {
            $aliases = \is_array($package['extra'] ?? null) && \is_array($package['extra']['branch-alias'] ?? null) ? $package['extra']['branch-alias'] : [];
            $alias = $aliases[$version] ?? null;

            return \is_string($alias) ? self::of($alias) : null;
        }

        return self::of($version);
    }
}
