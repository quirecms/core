<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\Exception\InvalidRoleException;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:create-role',
    description: 'Add a role to the site, optionally with the abilities of another',
    help: <<<'HELP'
        <info>%command.full_name% translator "Translator" --from=editor</info>
        <info>%command.full_name% translator "Übersetzer"</info>   (renames a role the site added)

        Give the role abilities with <info>quire:permissions:grant</info>, and users the role with <info>quire:user:role</info>.
        HELP,
)]
final readonly class CreateRoleCommand
{
    public function __construct(
        private SiteRoles $roles,
        private Permissions $permissions,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The role\'s name, e.g. translator')]
        string $name,
        #[Argument(description: 'What the role is called, e.g. "Translator"')]
        string $label,
        #[Option(description: 'A role whose abilities it starts with, e.g. editor')]
        ?string $from = null,
    ): int {
        try {
            if (null === $from && isset($this->permissions->siteRoles()[$name])) {
                $this->roles->relabel($name, $label);
                $io->success(\sprintf('Renamed the role %s to "%s".', $name, $label));

                return Command::SUCCESS;
            }

            $this->roles->create($name, $label, $from);
        } catch (InvalidRoleException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created the role %s ("%s")%s.', $name, $label, null === $from ? '' : \sprintf(', with the abilities of %s', $from)));

        return Command::SUCCESS;
    }
}
