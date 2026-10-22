<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\Exception\InvalidUserException;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:delete',
    description: 'Delete a user, giving their content to another user or keeping it without an author',
    help: <<<'HELP'
        <info>%command.full_name% ada --reassign=grace</info>    (Grace becomes the author of Ada's content)
        <info>%command.full_name% ada --keep-content</info>      (the content stays, without an author)

        A user who wrote content needs one of the two. To keep someone from signing in without deleting
        them, use <info>quire:user:suspend</info>.
        HELP,
)]
final readonly class DeleteUserCommand
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
        #[Option(description: 'The ID, email or username of the user who gets their content')]
        ?string $reassign = null,
        #[Option(description: 'Keep their content without an author')]
        bool $keepContent = false,
    ): int {
        $found = $this->users->findByReference($user);
        $heir = null === $reassign ? null : $this->users->findByReference($reassign);

        if (!$found instanceof User || (null !== $reassign && !$heir instanceof User)) {
            $io->error(\sprintf('No user with the ID, email or username "%s".', $found instanceof User ? $reassign : $user));

            return Command::FAILURE;
        }

        if (null !== $reassign && $keepContent) {
            $io->error('Choose either --reassign or --keep-content.');

            return Command::FAILURE;
        }

        $count = $this->writer->contentCount($found->id);

        if ($count > 0 && !$heir instanceof User && !$keepContent) {
            $io->error(\sprintf('%s is the author of %d content item(s). Give them to another user with --reassign=<user>, or keep them without an author with --keep-content.', $found->displayName, $count));

            return Command::FAILURE;
        }

        try {
            $this->writer->delete($found->id, $heir?->id);
        } catch (InvalidUserException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(match (true) {
            0 === $count => \sprintf('Deleted %s.', $found->displayName),
            $heir instanceof User => \sprintf('Deleted %s. %s is now the author of their %d content item(s).', $found->displayName, $heir->displayName, $count),
            default => \sprintf('Deleted %s. Their %d content item(s) have no author now.', $found->displayName, $count),
        });

        return Command::SUCCESS;
    }
}
