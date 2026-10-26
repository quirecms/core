<?php

declare(strict_types=1);

namespace Quire\User\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Internal\Access;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\User\CreateUser as CreateUserCommand;
use Quire\User\Invitations;
use Quire\User\UserRepository;
use Quire\User\UserWriter;

/**
 * "user.invite": email people a link to choose their password. People who aren't users yet, given by
 * their email, are added first, with the roles given and without a password.
 *
 * @internal
 */
final readonly class InviteUsers implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Invitations $invitations,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Invite people', [
            new Parameter('users', ParameterType::User, 'People: emails, or users\' usernames', multiple: true),
            new Parameter('roles', ParameterType::Role, 'Roles of the people added', required: false, multiple: true),
        ], command: 'quire:user:invite');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): null
    {
        $reason = $this->invitations->unavailableReason();

        if (null !== $reason) {
            throw new \InvalidArgumentException($reason);
        }

        foreach ($arguments->strings('users') as $reference) {
            $user = $this->users->findByReference($reference) ?? (str_contains($reference, '@')
                ? $this->writer->create(new CreateUserCommand(displayName: explode('@', $reference)[0], email: $reference, roles: $arguments->strings('roles')))
                : throw new \InvalidArgumentException(\sprintf('There is no user "%s". Give an email to add someone new.', $reference)));

            $this->invitations->invite($user, $this->access->actor());
        }

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:invite', $arguments->strings('users')).CommandLine::repeated('role', $arguments->strings('roles'));
    }
}
