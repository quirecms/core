<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\Permissions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:reset',
    description: 'Give abilities their default roles again',
    help: <<<'HELP'
        <info>%command.full_name% content.page.publish</info>    (one ability)
        <info>%command.full_name%</info>                         (every ability: undoes all changes)
        HELP,
)]
final readonly class ResetCommand
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
        #[Argument(description: 'The abilities; all when none are given')]
        array $abilities = [],
    ): int {
        $this->permissions->restoreDefaults([] === $abilities ? null : $abilities);
        $io->success([] === $abilities ? 'Every ability has its default roles again.' : \sprintf('Back to the default roles: %s.', implode(', ', $abilities)));

        return Command::SUCCESS;
    }
}
