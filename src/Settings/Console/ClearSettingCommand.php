<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:clear',
    description: 'Remove a setting\'s stored value, even one nothing declares now',
    help: <<<'HELP'
        Removes the value stored for a setting: a declared one falls back to its default, and one
        nothing declares now (a disabled extension's) is gone, rather than coming back when it's enabled:

          <info>%command.full_name% quire-analytics.ga4_id</info>
        HELP,
)]
final readonly class ClearSettingCommand
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The setting key, e.g. quire-analytics.ga4_id')]
        string $key,
    ): int {
        try {
            $removed = $this->settings->forget($key);
        } catch (InvalidSettingValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($removed ? \sprintf('%s has no stored value now.', $key) : \sprintf('%s had no stored value.', $key));

        return Command::SUCCESS;
    }
}
