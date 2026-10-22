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

    private function reader(): ImportMapConfigReader
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
        );
    }
}
