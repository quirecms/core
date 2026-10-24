<?php

declare(strict_types=1);

namespace Quire\Extension\Console;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionSwitcher;
use Quire\Extension\Rebuild\RebuildFailedException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:extension:disable',
    description: 'Disable one or more extensions; their data is kept unless you pass --purge',
    help: <<<'HELP'
        Disables the given extensions. Their data stays in the database, so enabling them again
        restores them as they were.

        An extension can't be disabled while another enabled extension requires it. Disable both
        at once instead:

          <info>%command.full_name% acme-reviews acme-comments</info>

        To permanently delete an extension's data (its database tables and settings), add
        <comment>--purge</comment>. You'll be asked to confirm; without a terminal, also pass <comment>--force</comment>.
        HELP,
)]
final readonly class DisableExtensionCommand
{
    public function __construct(
        private ExtensionManager $extensions,
        private ExtensionSwitcher $switcher,
    ) {
    }

    /**
     * @param list<string> $ids
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'IDs of the extensions to disable')]
        array $ids,
        #[Option(description: 'Show what would be disabled without changing anything')]
        bool $dryRun = false,
        #[Option(description: 'Permanently delete the extensions\' data: roll back their migrations and delete their settings')]
        bool $purge = false,
        #[Option(description: 'Skip the confirmation for --purge')]
        bool $force = false,
    ): int {
        $readOnlyReason = $this->extensions->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->error($readOnlyReason);

            return Command::FAILURE;
        }

        try {
            $plan = $this->extensions->planDisable($ids);
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $plan) {
            $io->success(\sprintf('Already disabled: %s.', implode(', ', $ids)));

            return Command::SUCCESS;
        }

        if ($purge) {
            $problem = $this->switcher->purgeProblem($plan);

            if (null !== $problem) {
                $io->error($problem);

                return Command::FAILURE;
            }
        }

        if ($dryRun) {
            $io->writeln($purge ? 'Would disable and permanently delete the data of:' : 'Would disable:');
            $io->listing($plan);

            return Command::SUCCESS;
        }

        if ($purge && !$this->confirmPurge($io, $input, $plan, $force)) {
            return Command::FAILURE;
        }

        try {
            $this->switcher->disable($ids, $purge, $io->write(...));
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException|RebuildFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s: %s.', $purge ? 'Disabled and purged' : 'Disabled', implode(', ', $plan)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $plan
     */
    private function confirmPurge(SymfonyStyle $io, InputInterface $input, array $plan, bool $force): bool
    {
        $io->warning(\sprintf('This permanently deletes the data of: %s. Back up the database first if you might need it.', implode(', ', $plan)));

        if ($force) {
            return true;
        }

        if (!$input->isInteractive()) {
            $io->error('Pass --force to purge without confirmation.');

            return false;
        }

        if (!$io->confirm('Delete this data permanently?', false)) {
            $io->writeln('Nothing was changed.');

            return false;
        }

        return true;
    }
}
