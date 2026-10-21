<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Exception\UnknownSettingException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:settings:set',
    description: 'Change a site setting, or restore its default',
    help: <<<'HELP'
        Values are written the way they're typed:

          <info>%command.full_name% site.title "My site"</info>
          <info>%command.full_name% acme-reviews.require_approval false</info>
          <info>%command.full_name% acme-reviews.per_page 50</info>
          <info>%command.full_name% acme-reviews.tags '["php", "cms"]'</info>   (lists and JSON settings take JSON)

        Restore the declared default with <comment>--default</comment>:

          <info>%command.full_name% site.title --default</info>
        HELP,
)]
final readonly class SetSettingCommand
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The setting key, e.g. site.title')]
        string $key,
        #[Argument(description: 'The new value; omit it with --default')]
        ?string $value = null,
        #[Option(description: 'Restore the declared default value', name: 'default')]
        bool $restoreDefault = false,
    ): int {
        if ($restoreDefault === (null !== $value)) {
            $io->error('Pass either a value or --default.');

            return Command::FAILURE;
        }

        try {
            $definition = $this->schema->get($key);

            if ($restoreDefault) {
                $this->settings->restoreDefault($key);
            } else {
                $this->settings->set($key, $definition->type->parse((string) $value, $definition->choices));
            }
        } catch (UnknownSettingException|InvalidSettingValueException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s is now %s.', $key, $definition->sensitive ? '(hidden)' : SettingValueFormatter::format($this->settings->get($key))));

        return Command::SUCCESS;
    }
}
