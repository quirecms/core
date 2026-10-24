<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\Exception\InvalidRoleException;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:delete-role',
    description: 'Delete a role the site added: its grants, and the role from its users',
)]
final readonly class DeleteRoleCommand
{
    public function __construct(
        private SiteRoles $roles,
        private Permissions $permissions,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The role\'s name, e.g. translator')]
        string $name,
        #[Option(description: 'Don\'t ask, even when users have the role')]
        bool $force = false,
    ): int {
        // Only the site's own roles can be deleted: asking first would be pointless otherwise.
        $count = isset($this->permissions->siteRoles()[$name]) ? $this->users->count($name) : 0;

        if ($count > 0 && !$force && !$io->confirm(\sprintf('%d %s the role, and will lose it. Delete it?', $count, 1 === $count ? 'user has' : 'users have'), false)) {
            return Command::FAILURE;
        }

        try {
            $this->roles->delete($name);
        } catch (InvalidRoleException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Deleted the role %s.', $name));

        return Command::SUCCESS;
    }
}
