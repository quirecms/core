<?php

declare(strict_types=1);

namespace Quire\Content\Field\Console;

use Quire\Content\Field\FieldIndexes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:sync-indexes',
    description: 'Create and drop the database indexes of sortable and filterable fields',
    help: <<<'HELP'
        Makes the indexes match the fields that core and the enabled extensions declare as sortable or
        filterable. Indexes are built without blocking writes. Quire runs this when the container starts
        and after enabling extensions; run it yourself after changing field declarations in development.

          <info>%command.full_name% --dry-run</info>
        HELP,
)]
final readonly class SyncFieldIndexesCommand
{
    public function __construct(
        private FieldIndexes $indexes,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Show what would change without changing anything')]
        bool $dryRun = false,
    ): int {
        $result = $this->indexes->sync($dryRun, $io->writeln(...));

        if ([] === $result['created'] && [] === $result['dropped']) {
            $io->writeln('The field indexes are up to date.');
        }

        return Command::SUCCESS;
    }
}
