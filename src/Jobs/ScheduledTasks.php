<?php

declare(strict_types=1);

namespace Quire\Jobs;

use Quire\Extend\Jobs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The scheduled tasks of core and the enabled extensions (see {@see Jobs::schedule()}).
 *
 * @api
 */
final readonly class ScheduledTasks
{
    /** @var array<string, ScheduledTask> */
    private array $tasks;

    /**
     * @param list<array{name: string, frequency: string, message: class-string<Job>, owner: string, timezone: ?string}> $tasks
     */
    public function __construct(
        #[Autowire(param: Jobs::PARAMETER)]
        array $tasks,
    ) {
        $byName = [];

        foreach ($tasks as $task) {
            $byName[$task['name']] = ScheduledTask::fromArray($task);
        }

        $this->tasks = $byName;
    }

    /**
     * @return array<string, ScheduledTask> By name
     */
    public function all(): array
    {
        return $this->tasks;
    }

    public function find(string $name): ?ScheduledTask
    {
        return $this->tasks[$name] ?? null;
    }
}
