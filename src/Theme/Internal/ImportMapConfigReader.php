<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader as BaseReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntries;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;

/**
 * The site's importmap.php, plus an entrypoint for each script of Extend\Assets.
 *
 * As entrypoints, the scripts get what the site's own get: the files they import get versioned URLs
 * and are preloaded, and asset-map:compile includes them. The added entries are never written back to
 * importmap.php (e.g. by importmap:require).
 *
 * @internal
 */
final class ImportMapConfigReader extends BaseReader
{
    private ?ImportMapEntries $withScripts = null;

    /**
     * @param list<array{type: string, path: string, group: string, owner: string}> $assets
     * @param list<array{id: string, path: string}>                                 $themes The active theme and its parents
     */
    public function __construct(
        string $importMapConfigPath,
        RemotePackageStorage $remotePackageStorage,
        private readonly array $assets,
        private readonly array $themes,
    ) {
        parent::__construct($importMapConfigPath, $remotePackageStorage);
    }

    public function getEntries(): ImportMapEntries
    {
        if ($this->withScripts instanceof ImportMapEntries) {
            return $this->withScripts;
        }

        $entries = new ImportMapEntries(iterator_to_array(parent::getEntries(), false));

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
        $this->withScripts = null;

        parent::writeEntries(new ImportMapEntries(array_values(array_filter(
            iterator_to_array($entries, false),
            static fn (ImportMapEntry $entry): bool => !\in_array($entry->importName, $scripts, true),
        ))));
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
