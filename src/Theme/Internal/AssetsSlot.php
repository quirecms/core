<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Assets;
use Quire\Theme\SlotRendererInterface;
use Quire\Theme\ThemeAssets;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds the stylesheets and scripts registered with Extend\Assets to the "head" slot of the site's pages:
 * links to the stylesheets, then the importmap, which loads the scripts and preloads what they import.
 *
 * @internal
 */
final readonly class AssetsSlot implements SlotRendererInterface
{
    private const string GROUP = 'site';

    /**
     * @param list<array{type: string, path: string, group: string, owner: string}> $assets
     */
    public function __construct(
        private ThemeAssets $themeAssets,
        #[Autowire(service: 'asset_mapper.importmap.renderer')]
        private ImportMapRenderer $importMap,
        #[Autowire(service: AssetsPass::CONFIG_READER)]
        private ImportMapConfigReader $importMapConfig,
        #[Autowire(param: Assets::PARAMETER)]
        private array $assets,
    ) {
    }

    public function render(array $context): string
    {
        $stylesheets = [];
        $entrypoints = [];

        foreach ($this->assets as $asset) {
            if (self::GROUP !== $asset['group']) {
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
