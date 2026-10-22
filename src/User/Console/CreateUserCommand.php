<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Role;
use Quire\User\UserWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:user:create',
    description: 'Create a user',
    help: <<<'HELP'
        Creates a user who signs in with their email or username and a password:

          <info>%command.full_name% ada@example.com --name="Ada Lovelace" --admin</info>
          <info>%command.full_name% grace@example.com --role=editor</info>

        You're asked for the password. Without a terminal (e.g. in a script), a strong password is
        generated and shown once. Pass <comment>--no-password</comment> for users who sign in another way, e.g. with SSO.
        HELP,
)]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserWriter $writer,
    ) {
    }

    /**
     * @param list<string> $role
     */
    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The email address')]
        string $email,
        #[Option(description: 'The name shown for the user; derived from the email by default')]
        ?string $name = null,
        #[Option(description: 'The username, e.g. "ada"; derived from the email by default')]
        ?string $username = null,
        #[Option(description: 'A role to give the user: member, editor or admin (repeatable)')]
        array $role = [],
        #[Option(description: 'Make the user an admin (the same as --role=admin)')]
        bool $admin = false,
        #[Option(description: 'Create the user without a password')]
        bool $noPassword = false,
    ): int {
        $asked = $noPassword ? [null, false] : Passwords::askOrReport($io, $input->isInteractive());

        if (null === $asked) {
            return Command::FAILURE;
        }

        [$password, $generated] = $asked;
        $roles = $admin ? [...$role, Role::ADMIN] : $role;

        try {
            $user = $this->writer->create(new CreateUser(
                displayName: $name ?? self::nameFromEmail($email),
                email: $email,
                username: $username,
                password: $password,
                roles: $roles,
            ));
        } catch (InvalidUserException $e) {
            $io->error(array_values($e->violations));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created %s (username "%s"%s).', $user->displayName, $user->username, [] === $user->roles ? '' : ', '.implode(', ', $user->roles)));

        if ($generated) {
            $io->warning(\sprintf('The password is: %s', $password));
            $io->writeln('It isn\'t shown again. Change it with "bin/console quire:user:password".');
        }

        return Command::SUCCESS;
    }

    /**
     * "ada.lovelace@example.com" becomes "Ada Lovelace".
     */
    public static function nameFromEmail(string $email): string
    {
        $local = str_contains($email, '@') ? substr($email, 0, (int) strpos($email, '@')) : $email;

        return ucwords(trim((string) preg_replace('/[._+-]+/', ' ', $local)));
    }
}
