<?php

declare(strict_types=1);

namespace Quire\Permission\Console;

use Quire\Permission\AccessExplainer;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permissions:explain',
    description: 'Explain whether a user has an ability, and why',
    help: <<<'HELP'
        <info>%command.full_name% grace content.post.publish</info>

        It decides as is_granted() does, without a particular item: for some abilities, e.g. "edit_own",
        or when extensions' rules decide case by case, the answer can differ per item.
        HELP,
)]
final readonly class ExplainCommand
{
    public function __construct(
        private UserRepository $users,
        private AccessExplainer $explainer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The user\'s ID, email or username')]
        string $user,
        #[Argument(description: 'The ability, e.g. content.post.publish')]
        string $ability,
    ): int {
        $found = $this->users->findByReference($user);

        if (!$found instanceof User) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $user));

            return Command::FAILURE;
        }

        $explanation = $this->explainer->explain($found, $ability);
        $io->writeln(\sprintf('<%s>%s %s %s.</>', $explanation->granted ? 'info' : 'comment', $found->displayName, $explanation->granted ? 'may' : 'may not', $ability));
        $io->listing($explanation->reasons);

        return Command::SUCCESS;
    }
}
