<?php

declare(strict_types=1);

namespace Quire\Content\Type\Console;

use Quire\Content\Type\ContentTypeRegistry;
use Quire\Content\Type\Exception\UnknownContentTypeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quire:content-type:show', description: 'Show a content type and its fields')]
final readonly class ShowContentTypeCommand
{
    public function __construct(
        private ContentTypeRegistry $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The type ID, e.g. post')]
        string $type,
    ): int {
        try {
            $definition = $this->registry->get($type);
        } catch (UnknownContentTypeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Type' => $definition->id],
            ['Label' => \sprintf('%s / %s', $definition->label, $definition->pluralLabel)],
            ['Capabilities' => implode(', ', $definition->capabilities)],
            ['Permalink' => $definition->permalink],
            ['Sorted by' => $definition->sortField.' '.$definition->sortDirection],
            ['Registered by' => $definition->owner],
        );

        if ([] === $definition->fields) {
            $io->note('The type has no fields.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($definition->fields as $field) {
            $rules = array_filter([
                $field->required ? 'required' : null,
                [] === $field->choices ? null : 'one of '.implode(', ', $field->choices),
                null === $field->min ? null : 'min '.$field->min,
                null === $field->max ? null : 'max '.$field->max,
                null === $field->maxLength ? null : 'max length '.$field->maxLength,
                $field->sortable ? 'sortable' : null,
                $field->filterable ? 'filterable' : null,
            ]);

            $rows[] = [
                $field->name,
                $field->type->value,
                implode(', ', $rules),
                null === $field->default ? '' : json_encode($field->default, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                $field->owner,
            ];
        }

        $io->table(['Field', 'Type', 'Rules', 'Default', 'Declared by'], $rows);

        return Command::SUCCESS;
    }
}
