<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\Preference\InvalidPreferenceException;
use Quire\User\Preference\UserPreferences;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:preferences',
    description: 'Show or change a user\'s preferences',
    help: <<<'HELP'
        <info>%command.full_name% ada</info>                                     (shows them, changed ones marked)
        <info>%command.full_name% ada quire.language de</info>                   (the value as JSON, or as text)
        <info>%command.full_name% ada quire.language --default</info>            (back to the default)
        <info>%command.full_name% ada --default</info>                           (all of them)
        HELP,
)]
final readonly class PreferencesCommand
{
    public function __construct(
        private UserRepository $users,
        private UserPreferences $preferences,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
        #[Argument(description: 'A preference, e.g. quire.language')]
        ?string $name = null,
        #[Argument(description: 'Its new value: JSON (true, 12, ["a"]) or text')]
        ?string $value = null,
        #[Option(description: 'Back to the default', name: 'default')]
        bool $restore = false,
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        if (null !== $name && !isset($this->preferences->definitions()[$name])) {
            $io->error(\sprintf('No preference "%s" is declared. They are: %s.', $name, implode(', ', array_keys($this->preferences->definitions()))));

            return Command::FAILURE;
        }

        try {
            if ($restore) {
                $this->preferences->restoreDefault($found, $name);
            } elseif (null !== $name && null !== $value) {
                $decoded = json_decode($value, true);
                $this->preferences->set($found, $name, null === $decoded && 'null' !== $value ? $value : $decoded);
            }

            $changed = $this->preferences->changed($found);
            $rows = [];

            foreach ($this->preferences->all($found) as $preference => $current) {
                if (null === $name || $preference === $name) {
                    $rows[] = [$preference, json_encode($current, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), \array_key_exists($preference, $changed) ? 'changed' : 'default'];
                }
            }
        } catch (InvalidPreferenceException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->table(['Preference', 'Value', ''], $rows);

        return Command::SUCCESS;
    }
}
