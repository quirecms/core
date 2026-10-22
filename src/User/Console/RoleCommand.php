<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\Exception\InvalidUserException;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:role',
    description: 'Show, add or remove a user\'s roles',
    help: <<<'HELP'
        <info>%command.full_name% ada</info>                       (shows the roles)
        <info>%command.full_name% ada --add=editor</info>
        <info>%command.full_name% ada --add=admin --remove=editor</info>
        HELP,
)]
final readonly class RoleCommand
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
    ) {
    }

    /**
     * @param list<string> $add
     * @param list<string> $remove
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
        #[Option(description: 'A role to add (repeatable)')]
        array $add = [],
        #[Option(description: 'A role to remove (repeatable)')]
        array $remove = [],
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof \Quire\User\User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        if ([] !== $add || [] !== $remove) {
            try {
                $found = $this->writer->setRoles($found->id, array_values(array_diff([...$found->roles, ...$add], $remove)));
            } catch (InvalidUserException $e) {
                $io->error(array_values($e->violations));

                return Command::FAILURE;
            }
        }

        $io->writeln(\sprintf('%s: %s', $found->displayName, [] === $found->roles ? 'no roles' : implode(', ', $found->roles)));

        return Command::SUCCESS;
    }
}
