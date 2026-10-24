<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Rebuild;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Rebuild\ConsoleAppRebuilder;
use Quire\Extension\Rebuild\RebuildFailedException;
use Quire\Tests\Runtime\FakeWorkerRestarter;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\Filesystem\Filesystem;

final class ConsoleAppRebuilderTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    private FakeWorkerRestarter $workers;

    protected function setUp(): void
    {
        $this->workers = new FakeWorkerRestarter();
    }

    public function testClearsTheCacheMigratesAndRestartsWorkers(): void
    {
        $output = $this->rebuild(migrate: true);

        self::assertSame([
            '/app/bin/console cache:clear --env=prod',
            '/app/bin/console quire:migrations:migrate --no-interaction --allow-no-migration --env=prod',
            '/app/bin/console quire:content:sync-indexes --env=prod',
            '/app/bin/console importmap:install --env=prod',
        ], $this->commands);
        self::assertSame(1, $this->workers->restarts);
        self::assertStringEndsWith("Restarted the PHP workers.\n", $output);
    }

    public function testWithoutMigrations(): void
    {
        $this->rebuild(migrate: false);

        self::assertSame(['/app/bin/console cache:clear --env=prod', '/app/bin/console importmap:install --env=prod'], $this->commands);
    }

    public function testRecompilesAssetsWhenTheSiteServesCompiledOnes(): void
    {
        $directory = sys_get_temp_dir().'/quire-compiled-'.bin2hex(random_bytes(4));
        $compiled = new CompiledAssetMapperConfigReader($directory);

        $this->rebuild(migrate: false, compiledAssets: $compiled);
        self::assertSame(['/app/bin/console cache:clear --env=prod', '/app/bin/console importmap:install --env=prod'], $this->commands, 'Not in development, where assets are served as they are.');

        $compiled->saveConfig('manifest.json', []);
        $this->commands = [];

        try {
            $this->rebuild(migrate: false, compiledAssets: $compiled);
        } finally {
            new Filesystem()->remove($directory);
        }

        self::assertSame(['/app/bin/console cache:clear --env=prod', '/app/bin/console importmap:install --env=prod', '/app/bin/console asset-map:compile --env=prod'], $this->commands);
    }

    public function testInDebugModeTheBuildIsWarmedUpRatherThanDeleted(): void
    {
        $rebuilder = new ConsoleAppRebuilder($this->workers, '/app', 'dev', function (array $command): int {
            $this->commands[] = implode(' ', \array_slice($command, 1));

            return 0;
        }, debug: true);

        $rebuilder->rebuild(false, static function (): void {});

        self::assertSame(['/app/bin/console cache:warmup --env=dev', '/app/bin/console importmap:install --env=dev'], $this->commands, 'A running process keeps using the build it started with.');
    }

    public function testReportsWhenThereAreNoWorkers(): void
    {
        $this->workers = new FakeWorkerRestarter(hasWorkers: false);

        self::assertStringEndsWith("No PHP workers to restart on this machine.\n", $this->rebuild(migrate: false));
    }

    public function testFailuresStopTheRebuild(): void
    {
        try {
            $this->rebuild(migrate: true, failing: 'cache:clear');
            self::fail('The failure was ignored.');
        } catch (RebuildFailedException $e) {
            self::assertSame('"bin/console cache:clear" failed with exit code 1. Fix the problem shown above, then run it again.', $e->getMessage());
            self::assertCount(1, $this->commands, 'Migrations did not run after the failure.');
            self::assertSame(0, $this->workers->restarts, 'Workers keep serving the previous build.');
        }
    }

    private function rebuild(bool $migrate, ?string $failing = null, ?CompiledAssetMapperConfigReader $compiledAssets = null): string
    {
        $rebuilder = new ConsoleAppRebuilder($this->workers, '/app', 'prod', function (array $command, \Closure $output) use ($failing): int {
            $this->commands[] = implode(' ', \array_slice($command, 1));
            $output('ran '.$command[2]."\n");

            return $command[2] === $failing ? 1 : 0;
        }, $compiledAssets);

        $output = '';
        $rebuilder->rebuild($migrate, static function (string $text) use (&$output): void {
            $output .= $text;
        });

        return $output;
    }
}
