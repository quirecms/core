<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Assets;
use Quire\Theme\ThemeAssets;
use Quire\Theme\ThemePreview;
use Quire\Theme\Themes;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Renders the stylesheets and scripts registered with Extend\Assets for a group of pages: links to the
 * stylesheets, then the importmap, which loads the scripts and preloads what they import.
 *
 * @internal
 */
final readonly class AssetsRenderer
{
    /**
     * @param list<array{type: string, path: string, group: string, owner: string}> $assets
     * @param list<array{id: string, path: string}>                                 $themes
     */
    public function __construct(
        private ThemeAssets $themeAssets,
        #[Autowire(service: 'asset_mapper.importmap.renderer')]
        private ImportMapRenderer $importMap,
        #[Autowire(service: AssetsPass::CONFIG_READER)]
        private ImportMapConfigReader $importMapConfig,
        #[Autowire(param: Assets::PARAMETER)]
        private array $assets,
        #[Autowire(param: Themes::CHAIN_PARAMETER)]
        private array $themes,
        private ThemePreview $preview,
    ) {
    }

    /**
     * What the extenders added; while someone previews a theme, its stylesheets instead of the theme in use's.
     *
     * @return list<array{type: string, path: string, group: string, owner: string}>
     */
    private function assets(): array
    {
        $previewed = $this->preview->chain();

        if (null === $previewed) {
            return $this->assets;
        }

        $inUse = array_column($this->themes, 'id');
        $kept = array_values(array_filter($this->assets, static fn (array $asset): bool => !\in_array($asset['owner'], $inUse, true)));

        return [...$kept, ...array_map(static fn (string $path): array => ['type' => 'stylesheet', 'path' => $path, 'group' => 'site', 'owner' => $previewed[0]['id']], $this->preview->stylesheets())];
    }

    /**
     * The group's stylesheets only, e.g. for a preview that runs no scripts.
     *
     * @param string $group "site" or "admin"
     */
    public function stylesheets(string $group): string
    {
        $html = '';

        foreach ($this->assets() as $asset) {
            if ($group === $asset['group'] && 'stylesheet' === $asset['type']) {
                $html .= \sprintf('<link rel="stylesheet" href="%s">'."\n", htmlspecialchars($this->themeAssets->url($asset['path'])));
            }
        }

        return $html;
    }

    /**
     * @param string $group "site" or "admin"
     */
    public function render(string $group): string
    {
        $stylesheets = [];
        $entrypoints = [];

        foreach ($this->assets() as $asset) {
            if ($group !== $asset['group']) {
                continue;
            }

            match ($asset['type']) {
                'stylesheet' => $stylesheets[$asset['path']] = $this->themeAssets->url($asset['path']),
                'script' => $entrypoints[] = $this->importMapConfig->resolve($asset['path']),
                default => $entrypoints[] = $asset['path'],
            };
        }

        $html = '';

        foreach ($stylesheets as $url) {
            $html .= \sprintf('<link rel="stylesheet" href="%s">'."\n", htmlspecialchars($url));
        }

        return [] === $entrypoints ? $html : $html.$this->importMap->render(array_values(array_unique($entrypoints)))."\n";
    }
}
