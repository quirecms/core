<?php

declare(strict_types=1);

namespace Quire\Extension;

use Quire\Extension\Exception\ExtensionDiscoveryException;
use Quire\Extension\Exception\InvalidManifestException;

/**
 * Turns a Composer package entry into an {@see ExtensionManifest}.
 *
 * The manifest lives under `extra.quire` in the package's composer.json. Every key is optional:
 * the ID and title are derived from the package name when they're missing.
 */
final class ManifestParser
{
    private const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const array KNOWN_KEYS = ['id', 'title', 'description', 'category', 'icon', 'requires', 'optional'];

    /**
     * @param array<mixed> $package           One entry of Composer's installed.json
     * @param string       $vendorComposerDir Directory of installed.json; install paths are relative to it
     *
     * @return ExtensionManifest|null Null when the package isn't a Quire extension or theme
     */
    public function parse(array $package, string $vendorComposerDir): ?ExtensionManifest
    {
        $type = ExtensionType::tryFrom(\is_string($package['type'] ?? null) ? $package['type'] : '');

        if (null === $type) {
            return null;
        }

        $name = $package['name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            throw new ExtensionDiscoveryException(\sprintf('Composer reports a package of type "%s" without a name. Run "composer install" to repair vendor/composer/installed.json.', $type->value));
        }

        $manifest = $this->manifestSection($name, $package);
        $id = $this->optionalString($name, $manifest, 'id') ?? self::idFromPackageName($name);

        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.id" is "%s", but IDs may only contain lowercase letters, digits and single hyphens, e.g. "%s".', $id, self::idFromPackageName($name)));
        }

        $requires = $this->idList($name, $manifest, 'requires', $id);
        $optional = $this->idList($name, $manifest, 'optional', $id);
        $overlap = array_values(array_intersect($requires, $optional));

        if ([] !== $overlap) {
            throw InvalidManifestException::forPackage($name, \sprintf('"%s" is listed in both "extra.quire.requires" and "extra.quire.optional". Keep it in only one of them.', $overlap[0]));
        }

        return new ExtensionManifest(
            id: $id,
            packageName: $name,
            type: $type,
            version: $this->version($package),
            path: $this->installPath($name, $package, $vendorComposerDir),
            title: $this->optionalString($name, $manifest, 'title') ?? self::titleFromPackageName($name),
            description: $this->optionalString($name, $manifest, 'description')
                ?? (\is_string($package['description'] ?? null) && '' !== $package['description'] ? $package['description'] : null),
            category: $this->optionalString($name, $manifest, 'category'),
            icon: $this->optionalString($name, $manifest, 'icon'),
            requires: $requires,
            optional: $optional,
        );
    }

    /**
     * Derives the default extension ID: "acme/reviews" becomes "acme-reviews".
     */
    public static function idFromPackageName(string $packageName): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($packageName)), '-');
    }

    /**
     * Derives the default title: "acme/quire-star-reviews" becomes "Star Reviews".
     */
    public static function titleFromPackageName(string $packageName): string
    {
        $shortName = str_contains($packageName, '/') ? substr($packageName, (int) strrpos($packageName, '/') + 1) : $packageName;
        $shortName = (string) preg_replace('/^quire[-_]/', '', $shortName);

        return ucwords(str_replace(['-', '_'], ' ', $shortName));
    }

    /**
     * @param array<mixed> $package
     *
     * @return array<string, mixed>
     */
    private function manifestSection(string $name, array $package): array
    {
        $extra = $package['extra'] ?? [];

        if (!\is_array($extra)) {
            throw InvalidManifestException::forPackage($name, '"extra" must be a JSON object.');
        }

        $manifest = $extra['quire'] ?? [];

        if (!\is_array($manifest) || ([] !== $manifest && array_is_list($manifest))) {
            throw InvalidManifestException::forPackage($name, '"extra.quire" must be a JSON object, e.g. {"title": "Reviews"}.');
        }

        $result = [];

        foreach ($manifest as $key => $value) {
            $key = (string) $key;

            if (!\in_array($key, self::KNOWN_KEYS, true)) {
                throw InvalidManifestException::forPackage($name, $this->unknownKeyMessage($key));
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function unknownKeyMessage(string $key): string
    {
        $message = \sprintf('"extra.quire.%s" is not a known key.', $key);

        foreach (self::KNOWN_KEYS as $known) {
            if (levenshtein($key, $known) <= 2) {
                return $message.\sprintf(' Did you mean "%s"?', $known);
            }
        }

        return $message.\sprintf(' Known keys: "%s".', implode('", "', self::KNOWN_KEYS));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function optionalString(string $name, array $manifest, string $key): ?string
    {
        if (!\array_key_exists($key, $manifest) || null === $manifest[$key]) {
            return null;
        }

        if (!\is_string($manifest[$key]) || '' === trim($manifest[$key])) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.%s" must be a non-empty string.', $key));
        }

        return trim($manifest[$key]);
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    private function idList(string $name, array $manifest, string $key, string $ownId): array
    {
        $value = $manifest[$key] ?? [];

        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.%s" must be a list of extension IDs, e.g. ["quire-comments"].', $key));
        }

        $ids = [];

        foreach ($value as $id) {
            if (!\is_string($id) || 1 !== preg_match(self::ID_PATTERN, $id)) {
                throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.%s" contains %s, which is not a valid extension ID. Use IDs like "quire-comments", not package names like "quire/comments".', $key, json_encode($id)));
            }

            if ($id === $ownId) {
                throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.%s" lists the extension itself ("%s").', $key, $id));
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<mixed> $package
     */
    private function version(array $package): string
    {
        foreach (['pretty_version', 'version'] as $key) {
            if (\is_string($package[$key] ?? null) && '' !== $package[$key]) {
                return $package[$key];
            }
        }

        return 'unknown';
    }

    /**
     * @param array<mixed> $package
     */
    private function installPath(string $name, array $package, string $vendorComposerDir): string
    {
        $relative = $package['install-path'] ?? null;

        if (!\is_string($relative) || '' === $relative) {
            throw new ExtensionDiscoveryException(\sprintf('[%s] Composer doesn\'t report an install path for this package. Run "composer install" to repair vendor/composer/installed.json.', $name));
        }

        $path = realpath($vendorComposerDir.\DIRECTORY_SEPARATOR.$relative);

        if (false === $path || !is_dir($path)) {
            throw new ExtensionDiscoveryException(\sprintf('[%s] The package should be installed at "%s", but that directory doesn\'t exist. Run "composer install".', $name, $vendorComposerDir.\DIRECTORY_SEPARATOR.$relative));
        }

        return $path;
    }
}
