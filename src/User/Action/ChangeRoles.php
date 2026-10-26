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
use Quire\User\Exception\UserNotFoundException;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;

/**
 * "user.change_roles": add roles to a user, and take roles from them.
 *
 * @internal
 */
final readonly class ChangeRoles implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Change a user\'s roles', [
            new Parameter('user', ParameterType::User, 'User'),
            new Parameter('add', ParameterType::Role, 'Roles to add', required: false, multiple: true),
            new Parameter('remove', ParameterType::Role, 'Roles to remove', required: false, multiple: true),
        ], command: 'quire:user:role');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): User
    {
        $user = $this->user($arguments->string('user'));
        $roles = array_values(array_unique([...array_diff($user->roles, $arguments->strings('remove')), ...$arguments->strings('add')]));

        return $this->writer->setRoles($user->id, $roles);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:role', [$arguments->string('user')]).CommandLine::repeated('add', $arguments->strings('add')).CommandLine::repeated('remove', $arguments->strings('remove'));
    }

    private function user(string $reference): User
    {
        return $this->users->findByReference($reference) ?? throw UserNotFoundException::for($reference);
    }
}
