<?php

declare(strict_types=1);

namespace Quire\Extension\Console;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManager;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:extension:disable',
    description: 'Disable one or more extensions; their data is kept',
    help: <<<'HELP'
        Disables the given extensions. Their data stays in the database, so enabling them again
        restores them as they were.

        An extension can't be disabled while another enabled extension requires it. Disable both
        at once instead:

          <info>%command.full_name% acme-reviews acme-comments</info>
        HELP,
)]
final readonly class DisableExtensionCommand
{
    public function __construct(
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * @param list<string> $ids
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'IDs of the extensions to disable')]
        array $ids,
        #[Option(description: 'Show what would be disabled without changing anything')]
        bool $dryRun = false,
    ): int {
        $readOnlyReason = $this->extensions->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->error($readOnlyReason);

            return Command::FAILURE;
        }

        try {
            $plan = $dryRun ? $this->extensions->planDisable($ids) : $this->extensions->disable($ids);
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $plan) {
            $io->success(\sprintf('Already disabled: %s.', implode(', ', $ids)));
        } elseif ($dryRun) {
            $io->writeln('Would disable:');
            $io->listing($plan);
        } else {
            $io->success(\sprintf('Disabled: %s.', implode(', ', $plan)));
        }

        return Command::SUCCESS;
    }
}
