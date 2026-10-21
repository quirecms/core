<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Rebuild;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Rebuild\ConsoleAppRebuilder;
use Quire\Extension\Rebuild\RebuildFailedException;

final class ConsoleAppRebuilderTest extends TestCase
{
    /** @var list<string> */
    private array $commands = [];

    public function testClearsTheCacheThenMigrates(): void
    {
        $this->rebuilder()->rebuild(true, static function (): void {});

        self::assertSame([
            '/app/bin/console cache:clear --env=prod',
            '/app/bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration --env=prod',
        ], $this->commands);
    }

    public function testWithoutMigrations(): void
    {
        $this->rebuilder()->rebuild(false, static function (): void {});

        self::assertSame(['/app/bin/console cache:clear --env=prod'], $this->commands);
    }

    public function testFailuresStopTheRebuild(): void
    {
        try {
            $this->rebuilder(failing: 'cache:clear')->rebuild(true, static function (): void {});
            self::fail('The failure was ignored.');
        } catch (RebuildFailedException $e) {
            self::assertSame('"bin/console cache:clear" failed with exit code 1. Fix the problem shown above, then run it again.', $e->getMessage());
            self::assertCount(1, $this->commands, 'Migrations did not run after the failure.');
        }
    }

    public function testOutputIsForwarded(): void
    {
        $output = '';
        $this->rebuilder()->rebuild(false, static function (string $text) use (&$output): void {
            $output .= $text;
        });

        self::assertSame('ran cache:clear', $output);
    }

    private function rebuilder(?string $failing = null): ConsoleAppRebuilder
    {
        return new ConsoleAppRebuilder('/app', 'prod', function (array $command, \Closure $output) use ($failing): int {
            self::assertSame(\PHP_BINARY, $command[0]);
            $this->commands[] = implode(' ', \array_slice($command, 1));
            $output('ran '.$command[2]);

            return $command[2] === $failing ? 1 : 0;
        });
    }
}
