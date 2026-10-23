<?php

declare(strict_types=1);

namespace Quire\Jobs;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Reports on the site's background jobs: what waits, which workers run, when scheduled tasks ran.
 *
 * @api
 */
final readonly class JobStatus
{
    public function __construct(
        private Connection $connection,
        private ScheduledTasks $tasks,
        #[Autowire(service: 'messenger.transport.'.Job::TRANSPORT)]
        private TransportInterface $transport,
    ) {
    }

    public function report(?\DateTimeImmutable $now = null): JobReport
    {
        $now ??= new \DateTimeImmutable();
        // The transport writes UTC times without a zone.
        $counts = $this->connection->fetchAssociative(
            "SELECT
                 count(*) FILTER (WHERE queue_name = 'default' AND delivered_at IS NULL AND available_at <= CAST(:now AS timestamp)) AS waiting,
                 count(*) FILTER (WHERE queue_name = 'default' AND delivered_at IS NULL AND available_at > CAST(:now AS timestamp)) AS delayed,
                 count(*) FILTER (WHERE queue_name = 'default' AND delivered_at IS NOT NULL) AS running,
                 count(*) FILTER (WHERE queue_name = 'failed') AS failed,
                 to_char(min(created_at) FILTER (WHERE queue_name = 'default' AND delivered_at IS NULL AND available_at <= CAST(:now AS timestamp)), 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS oldest
             FROM quire.job",
            ['now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        $counts = new Row(false === $counts ? [] : $counts);

        $workers = array_map(static function (array $values): array {
            $row = new Row($values);

            return [
                'id' => $row->string('id'),
                'host' => $row->string('host'),
                'pid' => $row->int('pid'),
                'transports' => array_values(array_filter(explode(',', $row->string('transports')), static fn (string $name): bool => '' !== $name)),
                'startedAt' => $row->date('started_at'),
                'seenAt' => $row->date('seen_at'),
            ];
        }, $this->connection->fetchAllAssociative(
            'SELECT id, host, pid, transports, started_at, seen_at FROM quire.job_worker WHERE seen_at >= CAST(? AS timestamptz) ORDER BY host, started_at',
            [$now->modify(\sprintf('-%d seconds', JobReport::ALIVE_SECONDS))->format('Y-m-d H:i:sP')],
        ));

        /** @var array<string, array<string, mixed>> $runs */
        $runs = $this->connection->fetchAllAssociativeIndexed('SELECT task, last_due_at, last_run_at, last_run_by FROM quire.job_schedule');
        $schedule = [];

        foreach ($this->tasks->all() as $name => $task) {
            $run = new Row($runs[$name] ?? []);
            $known = isset($runs[$name]);
            $schedule[] = [
                'task' => $task,
                'lastDueAt' => $known ? $run->date('last_due_at') : null,
                'lastRunAt' => $known ? $run->nullableDate('last_run_at') : null,
                'lastRunBy' => $known ? $run->nullableString('last_run_by') : null,
                'nextDueAt' => $task->nextOccurrence($now),
            ];
        }

        $oldest = $counts->nullableString('oldest');

        return new JobReport(
            queued: $this->transport instanceof DoctrineTransport,
            waiting: $counts->int('waiting'),
            delayed: $counts->int('delayed'),
            running: $counts->int('running'),
            failed: $counts->int('failed'),
            oldestWaiting: null === $oldest ? null : new \DateTimeImmutable($oldest),
            workers: $workers,
            schedule: $schedule,
            at: $now,
        );
    }
}
