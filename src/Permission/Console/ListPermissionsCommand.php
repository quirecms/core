<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\Permissions;
use Quire\User\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:list',
    description: 'Show which roles have which abilities',
    help: <<<'HELP'
        <info>%command.full_name%</info>
        <info>%command.full_name% --role=editor</info>             (only the abilities of one role)
        <info>%command.full_name% --prefix=content.post.</info>    (only some abilities)

        "yes" marks a default, "yes*" a grant and "no*" a revocation that differ from the defaults.
        Admins have every ability that isn't strict.
        HELP,
)]
final readonly class ListPermissionsCommand
{
    public function __construct(
        private Permissions $permissions,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only the abilities this role has')]
        ?string $role = null,
        #[Option(description: 'Only abilities starting with this, e.g. "content.post."')]
        ?string $prefix = null,
    ): int {
        if (null !== $role && !$this->permissions->hasRole($role)) {
            $io->error(\sprintf('Unknown role "%s". Roles: %s.', $role, implode(', ', array_keys($this->permissions->roles()))));

            return Command::FAILURE;
        }

        $roles = array_values(array_diff(array_keys($this->permissions->roles()), [Role::ADMIN]));
        $changes = $this->permissions->changes();
        $rows = [];

        foreach ($this->permissions->abilities() as $name => $ability) {
            if ((null !== $prefix && !str_starts_with($name, $prefix)) || (null !== $role && !$this->permissions->allows([$role], $name))) {
                continue;
            }

            $row = [$name.($ability['strict'] ? ' (strict)' : ''), $ability['label']];

            foreach ($roles as $roleName) {
                $has = \in_array($roleName, $this->permissions->rolesWith($name), true);
                $changed = \in_array($roleName, $changes[$has ? 'grant' : 'revoke'][$name] ?? [], true);
                $row[] = ($has ? 'yes' : ($changed ? 'no' : '')).($changed ? '*' : '');
            }

            $row[] = $this->permissions->allows([Role::ADMIN], $name) ? 'yes' : '';
            $rows[] = $row;
        }

        $io->table(['Ability', 'Description', ...$roles, Role::ADMIN], $rows);
        $io->writeln('* differs from the default. "guest" is everyone, signed in or not.');

        return Command::SUCCESS;
    }
}
