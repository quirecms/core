<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\Exception\InvalidUserException;
use Quire\User\Invitations;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

#[AsCommand(
    name: 'quire:user:invite',
    description: 'Email a user a link to choose their password; it works once, for seven days',
)]
final readonly class InviteUserCommand
{
    public function __construct(
        private UserRepository $users,
        private Invitations $invitations,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        try {
            $this->invitations->invite($found);
        } catch (InvalidUserException $e) {
            $io->error(array_values($e->violations));

            return Command::FAILURE;
        } catch (TransportExceptionInterface $e) {
            $io->error(\sprintf('The email couldn\'t be sent: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Invited %s (%s) to choose a password.', $found->displayName, $found->email));

        return Command::SUCCESS;
    }
}
