<?php

declare(strict_types=1);

namespace Quire\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Quire\Runtime\BackgroundRefresher;
use Quire\Runtime\PhpBinary;

final class BackgroundRefresherTest extends TestCase
{
    /** @var list<string> */
    private array $spawned = [];

    private int $now = 1_000;

    public function testStartsTheRefreshCommandDetachedAndLogsItsOutput(): void
    {
        self::assertTrue($this->refresher()->trigger());

        self::assertSame([
            \sprintf("'%s' '/app/bin/console' quire:app:refresh --env='prod' >> '/app/var/log/quire-refresh.log' 2>&1 &", PhpBinary::path()),
        ], $this->spawned);
    }

    public function testTriggersAtMostOncePerCooldown(): void
    {
        $refresher = $this->refresher();

        self::assertTrue($refresher->trigger());
        $this->now += 59;
        self::assertFalse($refresher->trigger());
        ++$this->now;
        self::assertTrue($refresher->trigger());

        self::assertCount(2, $this->spawned);
    }

    private function refresher(): BackgroundRefresher
    {
        return new BackgroundRefresher(
            '/app',
            'prod',
            cooldownSeconds: 60,
            spawn: function (string $command): void {
                $this->spawned[] = $command;
            },
            clock: fn (): int => $this->now,
        );
    }
}
