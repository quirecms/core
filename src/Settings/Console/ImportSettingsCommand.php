<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Doctrine\DBAL\Connection;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:import',
    description: 'Change settings to the values of a JSON file, e.g. one made by quire:settings:export',
    help: <<<'HELP'
        <info>%command.full_name% settings.json --dry-run</info>
        <info>%command.full_name% settings.json</info>
        <info>%command.full_name% - < settings.json</info>

        The file is a JSON object of setting keys and values. Settings it doesn't list keep their value.
        Every value is checked before any is changed: when one doesn't fit, nothing changes. Keys that
        no enabled extension declares fail the import, unless you pass --skip-unknown.

        A changed theme (theme.active) is used once the application is rebuilt: use quire:theme:use instead.
        HELP,
)]
final readonly class ImportSettingsCommand
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
        private Connection $connection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The JSON file; "-" reads standard input')]
        string $file,
        #[Option(description: 'Show what would change without changing it')]
        bool $dryRun = false,
        #[Option(description: 'Leave out keys that no enabled extension declares, instead of failing')]
        bool $skipUnknown = false,
    ): int {
        $json = '-' === $file ? stream_get_contents(\STDIN) : (is_file($file) ? file_get_contents($file) : false);

        if (false === $json) {
            $io->error(\sprintf('Can\'t read "%s".', $file));

            return Command::FAILURE;
        }

        try {
            $values = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(\sprintf('The file isn\'t JSON: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!\is_array($values) || ([] !== $values && array_is_list($values))) {
            $io->error('The file must hold a JSON object of setting keys and values, e.g. {"site.title": "News"}.');

            return Command::FAILURE;
        }

        $changes = [];
        $errors = [];
        $skipped = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (!$this->schema->has($key)) {
                $skipUnknown ? $skipped[] = $key : $errors[] = \sprintf('%s: no enabled extension declares it.', $key);

                continue;
            }

            $definition = $this->schema->get($key);
            $error = 'environment' === $this->settings->source($key) ? \sprintf('it\'s set by the environment variable %s.', $definition->environmentVariable()) : $definition->validate($value);

            if (null !== $error) {
                $errors[] = \sprintf('%s: %s', $key, $error);
            } elseif ($this->settings->get($key) !== $value) {
                $changes[$key] = [$definition->sensitive ? '(hidden)' : SettingValueFormatter::format($this->settings->get($key)), $definition->sensitive ? '(hidden)' : SettingValueFormatter::format($value)];
            }
        }

        if ([] !== $errors) {
            $io->error(['Nothing was changed:', ...$errors]);

            return Command::FAILURE;
        }

        if ([] !== $skipped) {
            $io->note(\sprintf('Left out, as no enabled extension declares them: %s.', implode(', ', $skipped)));
        }

        if ([] === $changes) {
            $io->success('The settings already have these values.');

            return Command::SUCCESS;
        }

        $io->table(['Setting', 'Now', 'Then'], array_map(static fn (string $key, array $change): array => [$key, ...$change], array_keys($changes), $changes));

        if ($dryRun) {
            $io->note('Nothing was changed (--dry-run).');

            return Command::SUCCESS;
        }

        try {
            // All or none, should a validator of an extension refuse one.
            $this->connection->transactional(function () use ($changes, $values): void {
                foreach (array_keys($changes) as $key) {
                    $this->settings->set($key, $values[$key]);
                }
            });
        } catch (InvalidSettingValueException $e) {
            $this->settings->reset();
            $io->error(['Nothing was changed:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('Changed %d %s.', \count($changes), 1 === \count($changes) ? 'setting' : 'settings'));

        return Command::SUCCESS;
    }
}
