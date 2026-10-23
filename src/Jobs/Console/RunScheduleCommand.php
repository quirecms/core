<?php

declare(strict_types=1);

namespace Quire\Jobs\Console;

use Quire\Jobs\Internal\Heartbeats;
use Quire\Jobs\Internal\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:jobs:run-schedule',
    description: 'Dispatch the scheduled tasks that are due, for sites that run no worker',
    help: <<<'HELP'
        Workers ("bin/console messenger:consume quire") dispatch scheduled tasks by themselves. Without
        workers, e.g. with QUIRE_JOBS_DSN=sync://, run this every minute from cron:

            * * * * * cd /path/to/site && php bin/console quire:jobs:run-schedule

        Running it on several servers at once is safe: each task runs once per occurrence.
        HELP,
)]
final readonly class RunScheduleCommand
{
    public function __construct(
        private Scheduler $scheduler,
        private Heartbeats $heartbeats,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->heartbeats->beat('cron:'.Heartbeats::host(), ['schedule']);
        $dispatched = $this->scheduler->run(Heartbeats::host().':cron');

        $io->writeln([] === $dispatched ? 'No task was due.' : 'Dispatched: '.implode(', ', $dispatched), SymfonyStyle::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
