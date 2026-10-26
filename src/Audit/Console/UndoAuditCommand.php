<?php

declare(strict_types=1);

namespace Quire\Audit\Console;

use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditUndo;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:audit:undo',
    description: 'Undo what an entry of the audit log records, while nothing changed it since',
    help: <<<'HELP'
        <info>%command.full_name% 0192b6a0-…</info>              (the entry's ID, from quire:audit:list)
        <info>%command.full_name% 0192b6a0-… --dry-run</info>    (only says how)
        HELP,
)]
final readonly class UndoAuditCommand
{
    public function __construct(
        private AuditLog $log,
        private AuditUndo $undo,
        private Actions $actions,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The entry\'s ID')]
        string $entry,
        #[Option(description: 'Only say how it would be undone', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $found = $this->log->get($entry);

        if (!$found instanceof \Quire\Audit\AuditEntry) {
            $io->error(\sprintf('No entry "%s" in the audit log.', $entry));

            return Command::FAILURE;
        }

        $plan = $this->undo->plan($found);

        if (!$plan instanceof \Quire\Audit\UndoPlan) {
            $io->error('This can\'t be undone: what it changed was changed again since, or it can\'t be taken back.');

            return Command::FAILURE;
        }

        $command = $this->actions->commandLine($plan->action, $plan->arguments) ?? $plan->action;

        if ($dryRun) {
            $io->writeln($command);

            return Command::SUCCESS;
        }

        try {
            $this->undo->undo($found);
        } catch (ActionDeniedException|InvalidArgumentsException|\InvalidArgumentException|\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Undone, as: %s', $command));

        return Command::SUCCESS;
    }
}
