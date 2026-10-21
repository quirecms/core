<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Exception\UnknownSettingException;
use Quire\Settings\Settings;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:get',
    description: 'Print the current value of a site setting',
    help: 'Prints the value only, so it can be used in scripts. Strings are printed as-is; other values as JSON.',
)]
final readonly class GetSettingCommand
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The setting key, e.g. site.title')]
        string $key,
    ): int {
        try {
            $io->writeln(SettingValueFormatter::format($this->settings->get($key)), SymfonyStyle::OUTPUT_RAW);
        } catch (UnknownSettingException|InvalidSettingValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
