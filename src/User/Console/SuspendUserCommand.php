<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:suspend',
    description: 'Suspend a user: they can\'t sign in, and are signed out on their next request',
)]
final readonly class SuspendUserCommand
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
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof \Quire\User\User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        $this->writer->suspend($found->id);
        $io->success(\sprintf('Suspended %s.', $found->displayName));

        return Command::SUCCESS;
    }
}
