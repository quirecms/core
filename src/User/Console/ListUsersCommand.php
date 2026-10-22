<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:list',
    description: 'List users',
)]
final readonly class ListUsersCommand
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only users with this role')]
        ?string $role = null,
        #[Option(description: 'How many users to show')]
        int $limit = 100,
        #[Option(description: 'How many users to skip')]
        int $offset = 0,
    ): int {
        $users = $this->users->all($role, $limit, $offset);

        if ([] === $users) {
            $io->writeln(null === $role ? 'No users yet. Create one with "bin/console quire:user:create".' : \sprintf('No users with the role "%s".', $role));

            return Command::SUCCESS;
        }

        $io->table(['Username', 'Name', 'Email', 'Roles', 'Status', 'Last sign-in'], array_map(static fn (User $user): array => [
            $user->username,
            $user->displayName,
            $user->email ?? '',
            implode(', ', $user->roles),
            $user->status,
            $user->lastLoginAt?->format('Y-m-d H:i') ?? 'never',
        ], $users));

        $total = $this->users->count($role);

        if ($total > \count($users)) {
            $io->writeln(\sprintf('Showing %d to %d of %d. Use --offset to see more.', $offset + 1, $offset + \count($users), $total));
        }

        return Command::SUCCESS;
    }
}
