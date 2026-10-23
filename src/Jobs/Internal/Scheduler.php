<?php

declare(strict_types=1);

namespace Quire\Jobs\Internal;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;
use Quire\Jobs\ScheduledTasks;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches the scheduled tasks that are due. Every worker runs it every few seconds (and
 * `quire:jobs:run-schedule` from cron, for sites without workers).
 *
 * Each task has a row holding the occurrence that ran last. Running an occurrence moves it forward
 * with a conditional UPDATE, and dispatches the job in the same transaction: of all the workers that
 * find the task due, exactly one updates the row, so the job is dispatched once, and only if the update
 * is committed. No lock is held and no cache is shared, so it works the same on one server or many, and
 * behind connection poolers.
 *
 * @internal
 */
final readonly class Scheduler
{
    public function __construct(
        private Connection $connection,
        private ScheduledTasks $tasks,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param string $runner Who runs it, e.g. "web-1:4711", for the status
     *
     * @return list<string> The tasks dispatched
     */
    public function run(string $runner, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        /** @var array<string, string> $lastDue */
        $lastDue = $this->connection->fetchAllKeyValue('SELECT task, last_due_at FROM quire.job_schedule');
        $dispatched = [];

        foreach ($this->tasks->all() as $name => $task) {
            $due = $task->lastOccurrence($now);

            if (!isset($lastDue[$name])) {
                // A task seen for the first time starts with its next occurrence, rather than running at once on
                // every deployment of a new site.
                $this->connection->executeStatement(
                    'INSERT INTO quire.job_schedule (task, last_due_at) VALUES (?, ?) ON CONFLICT (task) DO NOTHING',
                    [$name, $due->format('Y-m-d H:i:sP')],
                );

                continue;
            }

            if ($due <= new Row(['at' => $lastDue[$name]])->date('at')) {
                continue;
            }

            $claimed = $this->connection->transactional(function () use ($name, $task, $due, $runner): bool {
                $updated = $this->connection->executeStatement(
                    'UPDATE quire.job_schedule SET last_due_at = :due, last_run_at = now(), last_run_by = :runner WHERE task = :task AND last_due_at < :due',
                    ['task' => $name, 'due' => $due->format('Y-m-d H:i:sP'), 'runner' => $runner],
                );

                if (1 === $updated) {
                    $this->bus->dispatch(new ($task->message)());
                }

                return 1 === $updated;
            });

            if ($claimed) {
                $dispatched[] = $name;
            }
        }

        return $dispatched;
    }
}
