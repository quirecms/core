<?php

declare(strict_types=1);

namespace Quire\Jobs\Console;

use Quire\Jobs\JobStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:jobs:status',
    description: 'Show the background jobs: what waits, which workers run, when scheduled tasks ran',
    help: <<<'HELP'
        <info>%command.full_name%</info>
        <info>%command.full_name% --check</info>    (fails when something needs attention, for monitoring)
        HELP,
)]
final readonly class StatusCommand
{
    public function __construct(
        private JobStatus $status,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Exit with an error when jobs aren\'t run, or failed')]
        bool $check = false,
    ): int {
        $report = $this->status->report();

        if ($report->queued) {
            $io->definitionList(
                ['Waiting' => $report->waiting.($report->oldestWaiting instanceof \DateTimeImmutable ? ', the oldest since '.$report->oldestWaiting->setTimezone($report->at->getTimezone())->format('Y-m-d H:i:s') : '')],
                ['Delayed' => (string) $report->delayed],
                ['Running' => (string) $report->running],
                ['Failed' => (string) $report->failed],
            );
        } else {
            $io->note('Jobs run as soon as they\'re dispatched (QUIRE_JOBS_DSN isn\'t a queue), so none wait.');
        }

        $io->section('Workers');

        if ([] === $report->workers) {
            $io->text('None seen in the last 10 minutes.');
        } else {
            $io->table(['Host', 'Process', 'Works on', 'Started', 'Last seen'], array_map(static fn (array $worker): array => [
                $worker['host'], $worker['pid'], implode(', ', $worker['transports']), $worker['startedAt']->format('Y-m-d H:i:s'), $worker['seenAt']->format('Y-m-d H:i:s'),
            ], $report->workers));
        }

        $io->section('Scheduled tasks');
        $io->table(['Task', 'Frequency', 'Last run', 'Next', 'By'], array_map(static fn (array $entry): array => [
            $entry['task']->name,
            $entry['task']->frequency.(null === $entry['task']->timezone ? '' : ' ('.$entry['task']->timezone.')'),
            $entry['lastRunAt']?->format('Y-m-d H:i:s') ?? 'not yet',
            $entry['nextDueAt']->setTimezone($report->at->getTimezone())->format('Y-m-d H:i:s'),
            $entry['lastRunBy'] ?? '',
        ], $report->schedule));

        $problems = $report->problems();

        if ([] === $problems) {
            $io->success('Background jobs run.');

            return Command::SUCCESS;
        }

        $io->warning($problems);

        return $check ? Command::FAILURE : Command::SUCCESS;
    }
}
