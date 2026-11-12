<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Csp\Csp;
use Quire\Extend\Assets;
use Quire\Http\Controller\EmptyModuleController;
use Quire\Theme\ThemeAssets;
use Quire\Theme\ThemePreview;
use Quire\Theme\Themes;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renders the stylesheets and scripts registered with Extend\Assets for a group of pages: links to the
 * stylesheets, then the importmap, which loads the scripts and preloads what they import.
 *
 * @internal
 */
final readonly class AssetsRenderer
{
    private const string DATA_MODULE = 'data:application/javascript,';

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
        private Csp $csp,
        private RequestStack $requests,
        private UrlGeneratorInterface $urls,
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

        if ([] === $entrypoints) {
            return $html;
        }

        $importMap = $this->importMap->render(array_values(array_unique($entrypoints)));

        if ('site' === $group) {
            $importMap = $this->withoutDataModules($importMap);
        }

        $request = $this->requests->getMainRequest();

        // The importmap, its loader for older browsers and the entry points are inline scripts: allowed
        // by their hashes, which are the same for every visitor.
        if ($request instanceof \Symfony\Component\HttpFoundation\Request && false !== preg_match_all('#<script\b(?![^>]*\ssrc=)[^>]*>(.*?)</script>#s', $importMap, $scripts)) {
            foreach ($scripts[1] as $script) {
                $this->csp->allowScript($request, $script);
            }
        }

        return $html.$importMap."\n";
    }

    /**
     * AssetMapper writes the stylesheets that scripts import as "data:" modules in the importmap, which
     * the site's Content-Security-Policy refuses (allowing "data:" scripts would let injected ones run),
     * and a refused import stops the whole script. So on the site's pages, a stylesheet that's linked
     * already imports an empty module of the site's, and one a script would add is linked here instead.
     * Other "data:" modules (JSON files) stay as they are, and are refused.
     */
    private function withoutDataModules(string $html): string
    {
        if (1 !== preg_match('#(<script type="importmap"[^>]*>\s*)(.*?)(\s*</script>)#s', $html, $match, \PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $map = json_decode($match[2][0], true);

        if (!\is_array($map) || !\is_array($map['imports'] ?? null)) {
            return $html;
        }

        $empty = $this->urls->generate(EmptyModuleController::ROUTE);
        $links = '';
        $changed = false;

        foreach ($map['imports'] as $name => $url) {
            if (!\is_string($url) || !str_starts_with($url, self::DATA_MODULE)) {
                continue;
            }

            $code = substr($url, \strlen(self::DATA_MODULE));

            if ('' === $code) {
                $map['imports'][$name] = $empty;
                $changed = true;
            } elseif (1 === preg_match('#^document\.head\.appendChild\(Object\.assign\(document\.createElement\(`link`\),\{rel:`stylesheet`,href:`([^`\\\\$]+)`\}\)\)$#', $code, $stylesheet)) {
                $map['imports'][$name] = $empty;
                $links .= \sprintf("\n".'<link rel="stylesheet" href="%s">', htmlspecialchars($stylesheet[1]));
                $changed = true;
            }
        }

        if (!$changed) {
            return $html;
        }

        $json = json_encode($map, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG);

        return substr($html, 0, $match[0][1]).$links.$match[1][0].$json.$match[3][0].substr($html, $match[0][1] + \strlen($match[0][0]));
    }
}
