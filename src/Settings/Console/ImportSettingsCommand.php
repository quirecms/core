<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\SettingsTransfer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:import',
    description: 'Change settings to the values of a JSON or YAML file, e.g. one made by quire:settings:export',
    help: <<<'HELP'
        <info>%command.full_name% settings.yaml --dry-run</info>
        <info>%command.full_name% settings.json</info>
        <info>%command.full_name% - < settings.yaml</info>

        The file holds setting keys and values, as YAML or a JSON object. Settings it doesn't list keep their value.
        Every value is checked before any is changed: when one doesn't fit, nothing changes. Keys that
        no enabled extension declares fail the import, unless you pass --skip-unknown.

        A changed theme (theme.active) is used once the application is rebuilt: use quire:theme:use instead.
        HELP,
)]
final readonly class ImportSettingsCommand
{
    public function __construct(
        private SettingsTransfer $transfer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The file, JSON or YAML; "-" reads standard input')]
        string $file,
        #[Option(description: 'Show what would change without changing it')]
        bool $dryRun = false,
        #[Option(description: 'Leave out keys that no enabled extension declares, instead of failing')]
        bool $skipUnknown = false,
    ): int {
        $content = '-' === $file ? stream_get_contents(\STDIN) : (is_file($file) ? file_get_contents($file) : false);

        if (false === $content) {
            $io->error(\sprintf('Can\'t read "%s".', $file));

            return Command::FAILURE;
        }

        try {
            $import = $this->transfer->plan($this->transfer->parse($content), $skipUnknown);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$import->canApply()) {
            $io->error(['Nothing was changed:', ...$import->errors]);

            return Command::FAILURE;
        }

        if ([] !== $import->skipped) {
            $io->note(\sprintf('Left out, as no enabled extension declares them: %s.', implode(', ', $import->skipped)));
        }

        if ([] === $import->changes) {
            $io->success('The settings already have these values.');

            return Command::SUCCESS;
        }

        $shown = static fn (mixed $value, bool $sensitive): string => $sensitive ? '(hidden)' : SettingValueFormatter::format($value);
        $io->table(['Setting', 'Now', 'Then'], array_map(static fn (string $key, array $change): array => [$key, $shown($change['from'], $change['sensitive']), $shown($change['to'], $change['sensitive'])], array_keys($import->changes), $import->changes));

        if ($dryRun) {
            $io->note('Nothing was changed (--dry-run).');

            return Command::SUCCESS;
        }

        try {
            $count = $this->transfer->apply($import);
        } catch (InvalidSettingValueException $e) {
            $io->error(['Nothing was changed:', $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->success(\sprintf('Changed %d %s.', $count, 1 === $count ? 'setting' : 'settings'));

        return Command::SUCCESS;
    }
}
