<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Extension\Exception\ExtensionDiscoveryException;
use Quire\Extension\Exception\InvalidManifestException;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\ExtensionType;
use Quire\Extension\ManifestParser;

final class ManifestParserTest extends TestCase
{
    private FakeComposerInstallation $composer;

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation();
        $this->composer->addPackage('acme/reviews');
        $this->composer->addPackage('acme/quire-star-reviews');
    }

    public function testIgnoresPackagesThatAreNotQuireExtensions(): void
    {
        self::assertNull($this->parse(['name' => 'symfony/console', 'type' => 'library']));
        self::assertNull($this->parse(['name' => 'quire/core', 'type' => 'symfony-bundle']));
        self::assertNull($this->parse(['name' => 'acme/no-type']));
    }

    public function testDerivesDefaultsFromThePackage(): void
    {
        $manifest = $this->parse($this->package('acme/quire-star-reviews', ['description' => 'Stars for everything.']));

        self::assertNotNull($manifest);
        self::assertSame('acme-quire-star-reviews', $manifest->id);
        self::assertSame('acme/quire-star-reviews', $manifest->packageName);
        self::assertSame(ExtensionType::Extension, $manifest->type);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame($this->composer->packagePath('acme/quire-star-reviews'), $manifest->path);
        self::assertSame('Star Reviews', $manifest->title);
        self::assertSame('Stars for everything.', $manifest->description);
        self::assertNull($manifest->category);
        self::assertNull($manifest->icon);
        self::assertSame([], $manifest->requires);
        self::assertSame([], $manifest->optional);
    }

    public function testReadsAFullManifest(): void
    {
        $manifest = $this->parse($this->package('acme/reviews', [
            'type' => 'quire-theme',
            'description' => 'Composer description',
            'extra' => ['quire' => [
                'id' => 'acme-reviews',
                'title' => 'Reviews',
                'description' => 'Star ratings and reviews.',
                'category' => 'content',
                'icon' => 'star',
                'requires' => ['quire-comments', 'quire-comments'],
                'optional' => ['quire-taxonomy'],
            ]],
        ]));

        self::assertNotNull($manifest);
        self::assertSame('acme-reviews', $manifest->id);
        self::assertSame(ExtensionType::Theme, $manifest->type);
        self::assertSame('Reviews', $manifest->title);
        self::assertSame('Star ratings and reviews.', $manifest->description);
        self::assertSame('content', $manifest->category);
        self::assertSame('star', $manifest->icon);
        self::assertSame(['quire-comments'], $manifest->requires, 'Duplicates are removed.');
        self::assertSame(['quire-taxonomy'], $manifest->optional);
    }

    public function testReadsTheParentOfATheme(): void
    {
        $manifest = $this->parse($this->package('acme/reviews', ['type' => 'quire-theme', 'extra' => ['quire' => ['theme' => ['parent' => 'quire-theme-default']]]]));

        self::assertSame(ExtensionType::Theme, $manifest?->type);
        self::assertSame('quire-theme-default', $manifest->parent);
        self::assertNull($this->parse($this->package('acme/reviews'))?->parent);
    }

    public function testReadsTheNpmPackagesItsScriptsImport(): void
    {
        $manifest = $this->parse($this->package('acme/reviews', ['extra' => ['quire' => ['importmap' => [
            '@hotwired/turbo' => '8.0.23',
            'bootstrap/dist/css/bootstrap.min.css' => '5.3.8',
            'chart.js' => '4.5.0-rc.1',
        ]]]]));

        self::assertSame(['@hotwired/turbo' => '8.0.23', 'bootstrap/dist/css/bootstrap.min.css' => '5.3.8', 'chart.js' => '4.5.0-rc.1'], $manifest?->importmap);
        self::assertSame([], $this->parse($this->package('acme/reviews'))?->importmap);
    }

    public function testAcceptsAnEmptyManifestObject(): void
    {
        self::assertNotNull($this->parse($this->package('acme/reviews', ['extra' => ['quire' => []]])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidManifests(): iterable
    {
        yield 'extra is not an object' => [['extra' => 'nope'], '"extra" must be a JSON object'];
        yield 'manifest is a list' => [['extra' => ['quire' => ['acme']]], '"extra.quire" must be a JSON object'];
        yield 'invalid ID' => [['extra' => ['quire' => ['id' => 'Acme_Reviews']]], 'IDs may only contain lowercase letters, digits and single hyphens, e.g. "acme-reviews"'];
        yield 'typo in a key' => [['extra' => ['quire' => ['require' => []]]], '"extra.quire.require" is not a known key. Did you mean "requires"?'];
        yield 'unknown key' => [['extra' => ['quire' => ['colour' => 'red']]], 'Known keys:'];
        yield 'empty title' => [['extra' => ['quire' => ['title' => '  ']]], '"extra.quire.title" must be a non-empty string'];
        yield 'requires is not a list' => [['extra' => ['quire' => ['requires' => 'quire-comments']]], '"extra.quire.requires" must be a list of extension IDs'];
        yield 'package name instead of ID' => [['extra' => ['quire' => ['requires' => ['quire/comments']]]], 'not package names like "quire/comments"'];
        yield 'requires itself' => [['extra' => ['quire' => ['requires' => ['acme-reviews']]]], 'lists the extension itself ("acme-reviews")'];
        yield 'both required and optional' => [['extra' => ['quire' => ['requires' => ['quire-tags'], 'optional' => ['quire-tags']]]], '"quire-tags" is listed in both'];
        yield 'theme section in an extension' => [['extra' => ['quire' => ['theme' => ['parent' => 'acme-base']]]], '"extra.quire.theme" is only for packages of type "quire-theme"'];
        yield 'theme section is a list' => [['type' => 'quire-theme', 'extra' => ['quire' => ['theme' => ['acme-base']]]], '"extra.quire.theme" must be a JSON object'];
        yield 'unknown theme key' => [['type' => 'quire-theme', 'extra' => ['quire' => ['theme' => ['parnet' => 'acme-base']]]], '"extra.quire.theme.parnet" is not a known key. Known keys: parent.'];
        yield 'parent is a package name' => [['type' => 'quire-theme', 'extra' => ['quire' => ['theme' => ['parent' => 'acme/base']]]], '"extra.quire.theme.parent" must be the ID of a theme'];
        yield 'importmap is a list' => [['extra' => ['quire' => ['importmap' => ['@hotwired/turbo']]]], '"extra.quire.importmap" must be a JSON object of packages and their versions'];
        yield 'importmap names a URL' => [['extra' => ['quire' => ['importmap' => ['https://cdn.example.com/turbo.js' => '8.0.23']]]], 'which is not the name of an npm package'];
        yield 'importmap has a range' => [['extra' => ['quire' => ['importmap' => ['@hotwired/turbo' => '^8.0']]]], '"extra.quire.importmap.@hotwired/turbo" must be an exact version, such as "1.2.3", so every site gets the same file; got "^8.0".'];
        yield 'importmap version is not a string' => [['extra' => ['quire' => ['importmap' => ['@hotwired/turbo' => 8]]]], 'must be an exact version'];
        yield 'parent is the theme itself' => [['type' => 'quire-theme', 'extra' => ['quire' => ['theme' => ['parent' => 'acme-reviews']]]], 'is the theme itself'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('provideInvalidManifests')]
    public function testRejectsInvalidManifestsWithAnActionableMessage(array $overrides, string $expectedMessage): void
    {
        try {
            $this->parse($this->package('acme/reviews', $overrides));
        } catch (InvalidManifestException $e) {
            self::assertStringStartsWith('[acme/reviews] Invalid Quire manifest in composer.json: ', $e->getMessage());
            self::assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        self::fail('An invalid manifest was accepted.');
    }

    public function testFailsWhenTheInstallDirectoryIsMissing(): void
    {
        $this->expectException(ExtensionDiscoveryException::class);
        $this->expectExceptionMessageIsOrContains('[acme/gone] The package should be installed at');

        $this->parse(['name' => 'acme/gone', 'type' => 'quire-extension', 'install-path' => '../acme/gone']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function providePackageNames(): iterable
    {
        yield 'simple' => ['acme/reviews', 'acme-reviews', 'Reviews'];
        yield 'quire prefix' => ['quire/quire-admin', 'quire-quire-admin', 'Admin'];
        yield 'underscores and dots' => ['Acme.Labs/star_reviews', 'acme-labs-star-reviews', 'Star Reviews'];
    }

    #[DataProvider('providePackageNames')]
    public function testDerivesIdAndTitleFromPackageName(string $packageName, string $expectedId, string $expectedTitle): void
    {
        self::assertSame($expectedId, ManifestParser::idFromPackageName($packageName));
        self::assertSame($expectedTitle, ManifestParser::titleFromPackageName($packageName));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function package(string $name, array $overrides = []): array
    {
        return [
            'name' => $name,
            'version' => '1.2.3.0',
            'pretty_version' => '1.2.3',
            'type' => 'quire-extension',
            'install-path' => '../'.$name,
            ...$overrides,
        ];
    }

    /**
     * @param array<mixed> $package
     */
    private function parse(array $package): ?ExtensionManifest
    {
        return new ManifestParser()->parse($package, $this->composer->vendorComposerDir());
    }
}
