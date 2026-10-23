<?php

declare(strict_types=1);

namespace Quire\Jobs;

/**
 * The state of the site's background jobs, from {@see JobStatus::report()}.
 *
 * @api
 */
final readonly class JobReport
{
    /** Workers not seen for longer count as gone. Long jobs delay heartbeats, so this is generous. */
    public const int ALIVE_SECONDS = 600;

    /**
     * @param bool                                                                                                                                                $queued   Whether jobs wait in quire.job for workers (false with e.g. "sync://", where they run at once)
     * @param int                                                                                                                                                 $waiting  Jobs ready to run
     * @param int                                                                                                                                                 $delayed  Jobs waiting for a later time, e.g. a retry
     * @param int                                                                                                                                                 $running  Jobs a worker took
     * @param int                                                                                                                                                 $failed   Jobs that failed after their retries
     * @param list<array{id: string, host: string, pid: int, transports: list<string>, startedAt: \DateTimeImmutable, seenAt: \DateTimeImmutable}>                $workers  Workers seen recently, and runs of the schedule from cron ("schedule")
     * @param list<array{task: ScheduledTask, lastDueAt: ?\DateTimeImmutable, lastRunAt: ?\DateTimeImmutable, lastRunBy: ?string, nextDueAt: \DateTimeImmutable}> $schedule
     */
    public function __construct(
        public bool $queued,
        public int $waiting,
        public int $delayed,
        public int $running,
        public int $failed,
        public ?\DateTimeImmutable $oldestWaiting,
        public array $workers,
        public array $schedule,
        public \DateTimeImmutable $at,
    ) {
    }

    /**
     * What needs attention: jobs nobody runs, scheduled tasks that don't run, failed jobs.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $waitingSince = $this->oldestWaiting instanceof \DateTimeImmutable ? $this->at->getTimestamp() - $this->oldestWaiting->getTimestamp() : 0;
        $consumers = array_filter($this->workers, static fn (array $worker): bool => \in_array(Job::TRANSPORT, $worker['transports'], true));

        if ($this->queued && [] === $consumers && $waitingSince > 300) {
            $problems[] = \sprintf('%d job(s) have been waiting for %d minutes, and no worker is running. Start one with "bin/console messenger:consume quire".', $this->waiting, intdiv($waitingSince, 60));
        } elseif ($this->queued && $waitingSince > 1800) {
            $problems[] = \sprintf('Jobs have been waiting for %d minutes: the workers don\'t keep up. Run more of them.', intdiv($waitingSince, 60));
        }

        if ([] !== $this->schedule && [] === $this->workers) {
            $problems[] = 'Nothing has run the scheduled tasks for 10 minutes (e.g. publishing scheduled content). Start a worker with "bin/console messenger:consume quire", or run "bin/console quire:jobs:run-schedule" every minute from cron.';
        }

        if ($this->failed > 0) {
            $problems[] = \sprintf('%d job(s) failed after their retries. See them with "bin/console messenger:failed:show --transport=%s", and retry them with "messenger:failed:retry".', $this->failed, Job::FAILED_TRANSPORT);
        }

        return $problems;
    }
}
