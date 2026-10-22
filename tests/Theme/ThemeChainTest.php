<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\ExtensionType;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Internal\ThemeChain;

final class ThemeChainTest extends TestCase
{
    public function testResolvesTheThemeAndItsParentsClosestFirst(): void
    {
        $themes = $this->themes(['acme-night' => 'acme-dark', 'acme-dark' => 'quire-theme-default', 'quire-theme-default' => null]);

        self::assertSame(['acme-night', 'acme-dark', 'quire-theme-default'], array_map(
            static fn (ExtensionManifest $theme): string => $theme->id,
            ThemeChain::resolve($themes, 'acme-night', []),
        ));
    }

    /**
     * @return iterable<string, array{array<string, ?string>, string, list<string>, string}>
     */
    public static function provideUnusableThemes(): iterable
    {
        yield 'not installed' => [['acme-dark' => null], 'acme-light', [], 'No theme with ID "acme-light" is installed. Installed: "acme-dark".'];
        yield 'nothing installed' => [[], 'acme-light', [], 'No theme with ID "acme-light" is installed. Install one with "composer require <package>".'];
        yield 'parent not installed' => [['acme-night' => 'acme-dark', 'acme-dark' => 'acme-base'], 'acme-night', [], 'The theme "acme-dark" inherits from "acme-base", which isn\'t installed. Install it with Composer.'];
        yield 'circle' => [['acme-a' => 'acme-b', 'acme-b' => 'acme-a'], 'acme-a', [], 'The themes "acme-a" → "acme-b" → "acme-a" inherit from each other in a circle.'];
        yield 'parent requires an extension' => [['acme-dark' => 'acme-base', 'acme-base' => null], 'acme-dark', ['quire-other'], 'The theme "acme-base" requires the extension "acme-slider". Enable it with "bin/console quire:extension:enable acme-slider" first.'];
    }

    /**
     * @param array<string, ?string> $themes  Parent by ID
     * @param list<string>           $enabled
     */
    #[DataProvider('provideUnusableThemes')]
    public function testRefusesThemesThatCantBeUsed(array $themes, string $id, array $enabled, string $message): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessageIsOrContains($message);

        ThemeChain::resolve($this->themes($themes), $id, $enabled);
    }

    public function testAcceptsThemesWhoseRequiredExtensionsAreEnabled(): void
    {
        self::assertCount(2, ThemeChain::resolve($this->themes(['acme-dark' => 'acme-base', 'acme-base' => null]), 'acme-dark', ['acme-slider']));
    }

    /**
     * @param array<string, ?string> $parents Parent by ID; "acme-base" requires the extension "acme-slider"
     *
     * @return array<string, ExtensionManifest>
     */
    private function themes(array $parents): array
    {
        $themes = [];

        foreach ($parents as $id => $parent) {
            $themes[$id] = new ExtensionManifest($id, 'acme/'.$id, ExtensionType::Theme, '1.0.0', '/themes/'.$id, ucfirst($id), requires: 'acme-base' === $id ? ['acme-slider'] : [], parent: $parent);
        }

        return $themes;
    }
}
