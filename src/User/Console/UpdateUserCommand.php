<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\Exception\InvalidUserException;
use Quire\User\UpdateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:update',
    description: 'Change a user\'s name, email or username',
    help: <<<'HELP'
        <info>%command.full_name% ada --name="Ada Lovelace" --email=ada@example.org</info>

        Only what you give changes. Roles change with <info>quire:user:role</info>, passwords with <info>quire:user:password</info>.
        HELP,
)]
final readonly class UpdateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
        #[Option(description: 'The name shown for the user')]
        ?string $name = null,
        #[Option(description: 'The new email address')]
        ?string $email = null,
        #[Option(description: 'The new username')]
        ?string $username = null,
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        $update = new UpdateUser($found->id);

        if (null !== $name) {
            $update = $update->displayName($name);
        }

        if (null !== $email) {
            $update = $update->email($email);
        }

        if (null !== $username) {
            $update = $update->username($username);
        }

        if ([] === $update->changes) {
            $io->error('Give what to change: --name, --email or --username.');

            return Command::FAILURE;
        }

        try {
            $updated = $this->writer->update($update);
        } catch (InvalidUserException $e) {
            $io->error(array_values($e->violations));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Updated %s (%s).', $updated->displayName, $updated->username));

        return Command::SUCCESS;
    }
}
