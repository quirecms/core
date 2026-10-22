<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\Permissions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:grant',
    description: 'Give a role abilities',
    help: <<<'HELP'
        <info>%command.full_name% editor content.page.publish</info>
        <info>%command.full_name% member acme-reviews.write acme-reviews.edit_own</info>

        See the roles and abilities with <info>quire:permissions:list</info>; undo changes with <info>quire:permissions:reset</info>.
        HELP,
)]
final readonly class GrantCommand
{
    public function __construct(
        private Permissions $permissions,
    ) {
    }

    /**
     * @param list<string> $abilities
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The role, e.g. editor; "guest" is everyone')]
        string $role,
        #[Argument(description: 'The abilities, e.g. content.post.publish')]
        array $abilities,
    ): int {
        try {
            $this->permissions->grant($role, $abilities);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Granted for %s: %s.', $role, implode(', ', $abilities)));

        return Command::SUCCESS;
    }
}
