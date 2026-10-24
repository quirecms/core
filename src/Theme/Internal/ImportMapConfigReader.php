<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Symfony\Component\AssetMapper\Exception\RuntimeException;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader as BaseReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntries;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;

/**
 * The site's importmap.php, plus an entrypoint for each script of Extend\Assets, and the npm packages
 * that extensions and themes list in "extra.quire.importmap".
 *
 * As entrypoints, the scripts get what the site's own get: the files they import get versioned URLs
 * and are preloaded, and asset-map:compile includes them. The packages are downloaded like the site's
 * own, by importmap:install, and checked by importmap:audit and importmap:outdated. The site's
 * importmap.php wins: a package it lists is used in its version. None of the added entries are ever
 * written back to importmap.php (e.g. by importmap:require).
 *
 * @internal
 */
final class ImportMapConfigReader extends BaseReader
{
    private ?ImportMapEntries $withScripts = null;

    /** @var list<ImportMapEntry> The entries added for packages, to leave out when writing */
    private array $packageEntries = [];

    /**
     * @param list<array{type: string, path: string, group: string, owner: string}> $assets
     * @param list<array{id: string, path: string}>                                 $themes   The active theme and its parents
     * @param list<array{name: string, version: string, owner: string}>             $packages
     */
    public function __construct(
        string $importMapConfigPath,
        RemotePackageStorage $remotePackageStorage,
        private readonly array $assets,
        private readonly array $themes,
        private readonly array $packages = [],
    ) {
        parent::__construct($importMapConfigPath, $remotePackageStorage);
    }

    public function getEntries(): ImportMapEntries
    {
        if ($this->withScripts instanceof ImportMapEntries) {
            return $this->withScripts;
        }

        $entries = new ImportMapEntries(iterator_to_array(parent::getEntries(), false));
        $this->packageEntries = [];

        foreach ($this->packageVersions($entries) as $name => $version) {
            $entry = $this->createRemoteEntry($name, str_ends_with($name, '.css') ? ImportMapType::CSS : ImportMapType::JS, $version, $name, false);
            $entries->add($entry);
            $this->packageEntries[] = $entry;
        }

        foreach ($this->scripts() as $path) {
            if (!$entries->has($path)) {
                $entries->add(ImportMapEntry::createLocal($path, ImportMapType::JS, $path, true));
            }
        }

        return $this->withScripts = $entries;
    }

    public function writeEntries(ImportMapEntries $entries): void
    {
        $scripts = $this->scripts();
        $added = $this->packageEntries;
        $this->withScripts = null;

        // A package entry that's still the one added here is left out; one that importmap:require or
        // importmap:update replaced is the site's own now.
        parent::writeEntries(new ImportMapEntries(array_values(array_filter(
            iterator_to_array($entries, false),
            static fn (ImportMapEntry $entry): bool => !\in_array($entry->importName, $scripts, true) && !\in_array($entry, $added, true),
        ))));
    }

    /**
     * The version of each package that the site's importmap doesn't list itself.
     *
     * @return array<string, string>
     */
    private function packageVersions(ImportMapEntries $siteEntries): array
    {
        $owners = [];

        foreach ($this->packages as $package) {
            if (!$siteEntries->has($package['name'])) {
                $owners[$package['name']][$package['version']][] = $package['owner'];
            }
        }

        $versions = [];

        foreach ($owners as $name => $byVersion) {
            if (\count($byVersion) > 1) {
                ksort($byVersion, \SORT_NATURAL);
                $wanted = implode(', ', array_map(static fn (string $version, array $ids): string => \sprintf('%s by %s', $version, implode(' and ', $ids)), array_keys($byVersion), $byVersion));

                throw new RuntimeException(\sprintf('Extensions or themes need different versions of the package "%s": %s. The site can only use one. Choose it by adding the package to importmap.php, e.g. with "bin/console importmap:require %s@%s", and check that they work with it.', $name, $wanted, $name, array_key_last($byVersion)));
            }

            $versions[$name] = array_key_first($byVersion);
        }

        ksort($versions);

        return $versions;
    }

    /**
     * The logical paths of the scripts, with "theme/…" resolved to the theme that has the file.
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        $scripts = [];

        foreach ($this->assets as $asset) {
            if ('script' === $asset['type']) {
                $scripts[] = $this->resolve($asset['path']);
            }
        }

        return array_values(array_unique($scripts));
    }

    /**
     * The logical path of a script, with "theme/…" resolved to the closest theme that has the file.
     */
    public function resolve(string $path): string
    {
        if (!str_starts_with($path, 'theme/')) {
            return $path;
        }

        $relative = substr($path, \strlen('theme/'));

        foreach ($this->themes as $theme) {
            if (is_file($theme['path'].\DIRECTORY_SEPARATOR.'assets'.\DIRECTORY_SEPARATOR.$relative)) {
                return $theme['id'].'/'.$relative;
            }
        }

        return $path;
    }
}
