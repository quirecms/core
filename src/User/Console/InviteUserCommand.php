<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Invitations;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

#[AsCommand(
    name: 'quire:user:invite',
    description: 'Email people a link to choose their password; it works once, for seven days',
    help: <<<'HELP'
        <info>%command.full_name% ada</info>
        <info>%command.full_name% kim@example.com lin@example.com --role=editor</info>

        Give users by their ID, email or username. Emails of people who aren't users yet add them first,
        without a password and with the roles of --role.
        HELP,
)]
final readonly class InviteUserCommand
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Invitations $invitations,
    ) {
    }

    /**
     * @param list<string> $users
     * @param list<string> $role
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The users\' IDs, emails or usernames, or emails of people to add')]
        array $users,
        #[Option(description: 'A role to give the people added (repeatable)')]
        array $role = [],
    ): int {
        $reason = $this->invitations->unavailableReason();

        if (null !== $reason) {
            $io->error($reason);

            return Command::FAILURE;
        }

        $failed = 0;

        foreach ($users as $reference) {
            try {
                $user = $this->users->findByReference($reference) ?? $this->add($reference, $role);
                $this->invitations->invite($user);
                $io->writeln(\sprintf(' <info>✓</info> Invited %s (%s)', $user->displayName, $user->email));
            } catch (InvalidUserException $e) {
                ++$failed;
                $io->writeln(\sprintf(' <error>✗</error> %s: %s', $reference, implode(' ', $e->violations)));
            } catch (TransportExceptionInterface $e) {
                ++$failed;
                $io->writeln(\sprintf(' <error>✗</error> %s: the email couldn\'t be sent: %s', $reference, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $io->error(\sprintf('%d of %d couldn\'t be invited.', $failed, \count($users)));

            return Command::FAILURE;
        }

        $io->success(1 === \count($users) ? 'Invited them to choose a password.' : \sprintf('Invited %d people to choose a password.', \count($users)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $roles
     *
     * @throws InvalidUserException
     */
    private function add(string $reference, array $roles): User
    {
        if (!str_contains($reference, '@')) {
            throw InvalidUserException::single('user', \sprintf('No user with the ID, email or username "%s". Give an email to add someone new.', $reference));
        }

        return $this->writer->create(new CreateUser(explode('@', $reference)[0], $reference, roles: $roles));
    }
}
