<?php

declare(strict_types=1);

namespace Quire\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use Quire\Jobs\Job;
use Quire\Jobs\ScheduledTask;

final class ScheduledTaskTest extends TestCase
{
    public function testIntervalsCountFromMidnightUtc(): void
    {
        $task = $this->task('every 15 minutes');
        $now = new \DateTimeImmutable('2026-10-24 10:37:12 UTC');

        self::assertSame('2026-10-24T10:30:00+00:00', $task->lastOccurrence($now)->format('c'));
        self::assertSame('2026-10-24T10:45:00+00:00', $task->nextOccurrence($now)->format('c'));
        self::assertSame('2026-10-24T10:30:00+00:00', $task->lastOccurrence(new \DateTimeImmutable('2026-10-24 12:30:00 +02:00'))->format('c'), 'At the occurrence itself, in any zone.');
        self::assertSame('2026-10-24T00:00:00+00:00', $this->task('every 1 day')->lastOccurrence($now)->format('c'));
        self::assertSame('2026-10-24T06:00:00+00:00', $this->task('every 6 hours')->lastOccurrence($now)->format('c'));
    }

    public function testCronExpressionsInATimeZone(): void
    {
        $now = new \DateTimeImmutable('2026-10-24 10:37:12 UTC');

        self::assertSame('2026-10-24 03:30', $this->task('30 3 * * *')->lastOccurrence($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 03:30', $this->task('30 3 * * *')->nextOccurrence($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        $berlin = $this->task('0 9 * * *', 'Europe/Berlin');
        self::assertSame('2026-10-24 07:00', $berlin->lastOccurrence($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), '9:00 in Berlin is 7:00 UTC in summer time.');
        self::assertSame('2026-10-25 08:00', $berlin->nextOccurrence($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), 'And 8:00 UTC after the clocks went back.');
        self::assertSame('2026-10-24 10:00', $this->task('@hourly')->lastOccurrence($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function testFrequenciesAreChecked(): void
    {
        foreach (['every 1 minute', 'every 15 minutes', 'every 2 hours', 'every 1 day', '*/5 * * * *', '@daily'] as $valid) {
            self::assertNull(ScheduledTask::frequencyError($valid), $valid);
        }

        self::assertSame('Intervals are at least 1 minute.', ScheduledTask::frequencyError('every 0 minutes'));

        foreach (['every 30 seconds', 'every 1 week', 'hourly', '61 * * * *', ''] as $invalid) {
            self::assertNotNull(ScheduledTask::frequencyError($invalid), $invalid);
        }
    }

    private function task(string $frequency, ?string $timezone = null): ScheduledTask
    {
        return new ScheduledTask('core.test', $frequency, Job::class, 'core', $timezone);
    }
}
