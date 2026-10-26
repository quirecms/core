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

    private const array KNOWN_KEYS = ['id', 'title', 'description', 'category', 'icon', 'requires', 'optional', 'theme', 'importmap'];

    /** An npm package, optionally with a file inside it: "@hotwired/turbo", "bootstrap/dist/css/bootstrap.min.css" */
    private const string PACKAGE_PATTERN = '#^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*(?:/[A-Za-z0-9._-]+)*$#';

    /** An exact version, so that every site downloads the same file: "8.0.23", "1.0.0-beta.2" */
    private const string VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';

    private const array KNOWN_THEME_KEYS = ['parent', 'screenshot'];

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

        [$parent, $screenshot] = $this->theme($name, $type, $manifest, $id);

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
            parent: $parent,
            importmap: $this->importmap($name, $manifest),
            screenshot: $screenshot,
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
     * Reads "extra.quire.theme", which only themes have: {"parent": "quire-theme-default", "screenshot": "screenshot.png"}.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array{0: ?string, 1: ?string} The parent's ID, and the screenshot's path in the package
     */
    private function theme(string $name, ExtensionType $type, array $manifest, string $ownId): array
    {
        if (!\array_key_exists('theme', $manifest)) {
            return [null, null];
        }

        if (ExtensionType::Theme !== $type) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.theme" is only for packages of type "%s".', ExtensionType::Theme->value));
        }

        $theme = $manifest['theme'];

        if (!\is_array($theme) || ([] !== $theme && array_is_list($theme))) {
            throw InvalidManifestException::forPackage($name, '"extra.quire.theme" must be a JSON object, e.g. {"parent": "quire-theme-default"}.');
        }

        foreach (array_keys($theme) as $key) {
            if (!\in_array($key, self::KNOWN_THEME_KEYS, true)) {
                throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.theme.%s" is not a known key. Known keys: %s.', $key, implode(', ', self::KNOWN_THEME_KEYS)));
            }
        }

        $parent = $theme['parent'] ?? null;

        if (null !== $parent && (!\is_string($parent) || 1 !== preg_match(self::ID_PATTERN, $parent))) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.theme.parent" must be the ID of a theme, e.g. "quire-theme-default"; got %s.', json_encode($parent)));
        }

        if ($parent === $ownId) {
            throw InvalidManifestException::forPackage($name, '"extra.quire.theme.parent" is the theme itself.');
        }

        $screenshot = $theme['screenshot'] ?? null;

        if (null !== $screenshot && (!\is_string($screenshot) || 1 !== preg_match('~^(?!/)(?!.*\.\./)[\w./-]+\.(png|jpe?g|webp)$~i', $screenshot))) {
            throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.theme.screenshot" must be the path of a PNG, JPEG or WebP image in the package, e.g. "screenshot.png"; got %s.', json_encode($screenshot)));
        }

        return [$parent, $screenshot];
    }

    /**
     * Reads "extra.quire.importmap": {"@hotwired/turbo": "8.0.23"}.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, string>
     */
    private function importmap(string $name, array $manifest): array
    {
        $importmap = $manifest['importmap'] ?? [];

        if (!\is_array($importmap) || ([] !== $importmap && array_is_list($importmap))) {
            throw InvalidManifestException::forPackage($name, '"extra.quire.importmap" must be a JSON object of packages and their versions, e.g. {"@hotwired/turbo": "8.0.23"}.');
        }

        $packages = [];

        foreach ($importmap as $package => $version) {
            $package = (string) $package;

            if (1 !== preg_match(self::PACKAGE_PATTERN, $package)) {
                throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.importmap" lists "%s", which is not the name of an npm package, e.g. "@hotwired/turbo" or "bootstrap/dist/css/bootstrap.min.css".', $package));
            }

            if (!\is_string($version) || 1 !== preg_match(self::VERSION_PATTERN, $version)) {
                throw InvalidManifestException::forPackage($name, \sprintf('"extra.quire.importmap.%s" must be an exact version, such as "1.2.3", so every site gets the same file; got %s.', $package, json_encode($version)));
            }

            $packages[$package] = $version;
        }

        ksort($packages);

        return $packages;
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
