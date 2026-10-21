<?php

declare(strict_types=1);

namespace Quire\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Quire\Runtime\Console\RefreshCommand;
use Quire\Tests\Extension\FakeAppRebuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshCommandTest extends TestCase
{
    private string $projectDir;

    private FakeAppRebuilder $rebuilder;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/quire-refresh-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var', 0o777, true);
        $this->rebuilder = new FakeAppRebuilder();
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/var/quire-refresh.lock');
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testRebuildsWithoutMigrating(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['no-migrate'], $this->rebuilder->calls);
        self::assertStringContainsString('The application is up to date.', $tester->getDisplay());
    }

    public function testOnlyOneRefreshRunsAtATime(): void
    {
        $lock = fopen($this->projectDir.'/var/quire-refresh.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, \LOCK_EX);

        try {
            $tester = $this->tester();
            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('Another refresh is already running on this machine.', $tester->getDisplay());
            self::assertSame([], $this->rebuilder->calls);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    public function testReportsFailures(): void
    {
        $this->rebuilder->failNext = true;
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('failed with exit code 1', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Command(null, new RefreshCommand($this->rebuilder, $this->projectDir)));
    }
}
