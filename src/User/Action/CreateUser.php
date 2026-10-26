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
use Quire\User\User;
use Quire\User\UserWriter;

/**
 * "user.create": add a user, with or without a password. Its command asks for the password: passwords
 * are never on a command line.
 *
 * @internal
 */
final readonly class CreateUser implements ActionInterface
{
    public function __construct(
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Add a user', [
            new Parameter('email', ParameterType::Text, 'Email'),
            new Parameter('name', ParameterType::Text, 'Name', required: false),
            new Parameter('username', ParameterType::Text, 'Username', required: false),
            new Parameter('roles', ParameterType::Role, 'Roles', required: false, multiple: true),
            new Parameter('password', ParameterType::Text, 'Password', required: false, secret: true),
        ], command: 'quire:user:create');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): User
    {
        $email = $arguments->string('email');

        return $this->writer->create(new CreateUserCommand(
            displayName: $arguments->optionalString('name') ?? explode('@', $email)[0],
            email: $email,
            username: $arguments->optionalString('username'),
            password: $arguments->optionalString('password'),
            roles: $arguments->strings('roles'),
        ));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:create', [$arguments->string('email')], [
            'name' => $arguments->optionalString('name'),
            'username' => $arguments->optionalString('username'),
            'no-password' => $arguments->has('password') ? null : true,
        ]).CommandLine::repeated('role', $arguments->strings('roles'));
    }
}
