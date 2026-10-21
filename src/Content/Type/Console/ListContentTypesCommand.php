<?php

declare(strict_types=1);

namespace Quire\Content\Type\Console;

use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content-type:list',
    description: 'List the registered content types',
    help: <<<'HELP'
        Lists the content types registered by core and the enabled extensions, with their capabilities,
        default permalink pattern and default sort order.
        HELP,
)]
final readonly class ListContentTypesCommand
{
    public function __construct(
        private ContentTypeRegistry $registry,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->registry->all() as $id => $type) {
            $rows[] = [
                $id,
                $type->pluralLabel,
                implode(', ', $type->capabilities),
                $type->permalink,
                $type->sortField.' '.$type->sortDirection,
                $type->owner,
            ];
        }

        if ([] === $rows) {
            $io->note('No content types are registered.');

            return Command::SUCCESS;
        }

        $io->table(['Type', 'Label', 'Capabilities', 'Permalink', 'Sorted by', 'Registered by'], $rows);

        return Command::SUCCESS;
    }
}
