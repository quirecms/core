<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'quire:settings:export',
    description: 'Print the settings the site changed, as JSON, e.g. to copy them to another site with quire:settings:import',
    help: <<<'HELP'
        <info>%command.full_name% > settings.json</info>
        <info>%command.full_name% site. --all</info>

        Only settings stored in the database are printed: not defaults (unless --all), nor values of
        environment variables, which belong to the environment. Sensitive settings, e.g. API keys, are
        left out unless you pass --include-sensitive.
        HELP,
)]
final readonly class ExportSettingsCommand
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument(description: 'Only settings whose key starts with this prefix')]
        string $prefix = '',
        #[Option(description: 'Also settings at their default value')]
        bool $all = false,
        #[Option(description: 'Also sensitive settings, e.g. API keys')]
        bool $includeSensitive = false,
    ): int {
        $values = [];

        foreach ($this->schema->all($prefix) as $key => $definition) {
            $source = $this->settings->source($key);

            if ((!$definition->sensitive || $includeSensitive) && ('database' === $source || ($all && 'default' === $source))) {
                $values[$key] = $this->settings->get($key);
            }
        }

        ksort($values);
        $output->writeln([] === $values ? '{}' : json_encode($values, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
