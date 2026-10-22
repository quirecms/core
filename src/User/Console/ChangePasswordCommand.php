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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:password',
    description: 'Set a user\'s password',
    help: <<<'HELP'
        Sets a new password; you're asked for it. Without a terminal, a strong password is generated and
        shown once.

          <info>%command.full_name% ada@example.com</info>
          <info>%command.full_name% ada --remove</info>    (the user can then only sign in another way, e.g. with SSO)

        Changing the password signs the user out everywhere.
        HELP,
)]
final readonly class ChangePasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
        #[Option(description: 'Remove the password instead')]
        bool $remove = false,
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof \Quire\User\User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        $asked = $remove ? [null, false] : Passwords::askOrReport($io, $input->isInteractive());

        if (null === $asked) {
            return Command::FAILURE;
        }

        [$password, $generated] = $asked;

        try {
            $this->writer->changePassword($found->id, $password);
        } catch (InvalidUserException $e) {
            $io->error(array_values($e->violations));

            return Command::FAILURE;
        }

        $io->success($remove ? \sprintf('Removed the password of %s.', $found->displayName) : \sprintf('Changed the password of %s.', $found->displayName));

        if ($generated) {
            $io->warning(\sprintf('The new password is: %s', $password));
        }

        return Command::SUCCESS;
    }
}
