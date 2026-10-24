<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Quire\Theme\Internal\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\RemotePackageStorage;
use Symfony\Component\Filesystem\Filesystem;

final class ImportMapConfigReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-importmap-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/importmap.php', "<?php return ['app' => ['path' => './assets/app.js', 'entrypoint' => true]];");
        $filesystem->dumpFile($this->directory.'/themes/child/assets/menu.js', '');
        $filesystem->dumpFile($this->directory.'/themes/base/assets/menu.js', '');
        $filesystem->dumpFile($this->directory.'/themes/base/assets/search.js', '');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testAddsTheScriptsAsEntrypoints(): void
    {
        $entries = $this->reader()->getEntries();

        self::assertSame(['app', 'acme-reviews/reviews.js', 'quire-child/menu.js', 'quire-base/search.js'], array_map(static fn (ImportMapEntry $e): string => $e->importName, iterator_to_array($entries, false)));
        self::assertTrue($entries->get('acme-reviews/reviews.js')->isEntrypoint);
    }

    public function testResolvesThemePathsToTheClosestThemeWithTheFile(): void
    {
        self::assertSame('quire-child/menu.js', $this->reader()->resolve('theme/menu.js'));
        self::assertSame('quire-base/search.js', $this->reader()->resolve('theme/search.js'));
        self::assertSame('theme/missing.js', $this->reader()->resolve('theme/missing.js'));
    }

    public function testNeverWritesTheScriptsToImportmapPhp(): void
    {
        $reader = $this->reader();
        $entries = $reader->getEntries();
        $entries->add(ImportMapEntry::createLocal('chart', ImportMapType::JS, './assets/chart.js', false));

        $reader->writeEntries($entries);

        $written = include $this->directory.'/importmap.php';
        self::assertIsArray($written);
        self::assertSame(['app', 'chart'], array_keys($written));
    }

    public function testAddsThePackagesOfExtensionsAndThemes(): void
    {
        $entries = $this->reader([
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'quire-admin'],
            ['name' => 'tom-select/dist/css/tom-select.css', 'version' => '2.4.3', 'owner' => 'acme-tags'],
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'acme-reviews'],
        ])->getEntries();

        $turbo = $entries->get('@hotwired/turbo');
        self::assertTrue($turbo->isRemotePackage());
        self::assertSame('8.0.23', $turbo->version);
        self::assertFalse($turbo->isEntrypoint);
        self::assertSame(ImportMapType::JS, $turbo->type);
        self::assertStringStartsWith($this->directory.'/vendor/@hotwired/turbo/', $turbo->path, 'Downloaded where the site\'s own packages are.');
        self::assertSame(ImportMapType::CSS, $entries->get('tom-select/dist/css/tom-select.css')->type);
    }

    public function testThePackagesOfTheSitesImportmapWin(): void
    {
        new Filesystem()->dumpFile($this->directory.'/importmap.php', "<?php return ['@hotwired/turbo' => ['version' => '8.0.24']];");

        $entries = $this->reader([
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'quire-admin'],
            ['name' => '@hotwired/turbo', 'version' => '7.3.0', 'owner' => 'acme-reviews'],
        ])->getEntries();

        self::assertSame('8.0.24', $entries->get('@hotwired/turbo')->version, 'The site chose the version, which also settles different ones.');
    }

    public function testDifferentVersionsOfAPackageAreAnErrorTheSiteSettles(): void
    {
        $this->expectExceptionMessageIsOrContains('Extensions or themes need different versions of the package "@hotwired/turbo": 7.3.0 by acme-reviews, 8.0.23 by quire-admin and acme-cart. The site can only use one. Choose it by adding the package to importmap.php, e.g. with "bin/console importmap:require @hotwired/turbo@8.0.23", and check that they work with it.');

        $this->reader([
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'quire-admin'],
            ['name' => '@hotwired/turbo', 'version' => '7.3.0', 'owner' => 'acme-reviews'],
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'acme-cart'],
        ])->getEntries();
    }

    public function testNeverWritesThePackagesToImportmapPhpUnlessTheSiteRequiresThem(): void
    {
        $reader = $this->reader([
            ['name' => '@hotwired/turbo', 'version' => '8.0.23', 'owner' => 'quire-admin'],
            ['name' => '@hotwired/stimulus', 'version' => '3.2.2', 'owner' => 'quire-admin'],
        ]);
        $entries = $reader->getEntries();
        // What "importmap:require @hotwired/stimulus@3.2.1" does.
        $entries->add($reader->createRemoteEntry('@hotwired/stimulus', ImportMapType::JS, '3.2.1', '@hotwired/stimulus', false));

        $reader->writeEntries($entries);

        $written = include $this->directory.'/importmap.php';
        self::assertIsArray($written);
        self::assertSame(['app', '@hotwired/stimulus'], array_keys($written));
        self::assertSame(['version' => '3.2.1'], $written['@hotwired/stimulus']);
    }

    /**
     * @param list<array{name: string, version: string, owner: string}> $packages
     */
    private function reader(array $packages = []): ImportMapConfigReader
    {
        return new ImportMapConfigReader(
            $this->directory.'/importmap.php',
            new RemotePackageStorage($this->directory.'/vendor'),
            [
                ['type' => 'stylesheet', 'path' => 'acme-reviews/reviews.css', 'group' => 'site', 'owner' => 'acme-reviews'],
                ['type' => 'script', 'path' => 'acme-reviews/reviews.js', 'group' => 'site', 'owner' => 'acme-reviews'],
                ['type' => 'script', 'path' => 'theme/menu.js', 'group' => 'site', 'owner' => 'quire-base'],
                ['type' => 'script', 'path' => 'theme/search.js', 'group' => 'admin', 'owner' => 'quire-base'],
                ['type' => 'entrypoint', 'path' => 'app', 'group' => 'site', 'owner' => 'site'],
            ],
            [['id' => 'quire-child', 'path' => $this->directory.'/themes/child'], ['id' => 'quire-base', 'path' => $this->directory.'/themes/base']],
            $packages,
        );
    }
}
