<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:list',
    description: 'List site settings with their current values',
    help: <<<'HELP'
        Lists every declared setting, or only those whose key starts with a prefix:

          <info>%command.full_name% acme-reviews.</info>

        The <comment>Source</comment> column shows where the value comes from: an environment variable,
        the database, or the declared default. Sensitive values are hidden.
        HELP,
)]
final readonly class ListSettingsCommand
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Only list settings whose key starts with this prefix')]
        string $prefix = '',
    ): int {
        $rows = [];

        foreach ($this->schema->all($prefix) as $key => $definition) {
            $rows[] = [
                $key,
                $definition->sensitive ? '<comment>(hidden)</comment>' : SettingValueFormatter::format($this->settings->get($key)),
                $this->settings->source($key),
                $definition->type->value,
                $definition->owner,
            ];
        }

        if ([] === $rows) {
            $io->note('' === $prefix ? 'No settings are declared.' : \sprintf('No settings start with "%s".', $prefix));

            return Command::SUCCESS;
        }

        $io->table(['Key', 'Value', 'Source', 'Type', 'Declared by'], $rows);

        return Command::SUCCESS;
    }
}
