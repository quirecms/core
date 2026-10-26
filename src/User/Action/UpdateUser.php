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
use Quire\User\UpdateUser as UpdateUserCommand;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;

/**
 * "user.update": change a user's name, email or username.
 *
 * @internal
 */
final readonly class UpdateUser implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Change a user\'s profile', [
            new Parameter('user', ParameterType::User, 'User'),
            new Parameter('name', ParameterType::Text, 'Name', required: false),
            new Parameter('email', ParameterType::Text, 'Email', required: false),
            new Parameter('username', ParameterType::Text, 'Username', required: false),
        ], command: 'quire:user:update');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): User
    {
        $update = new UpdateUserCommand($this->user($arguments->string('user'))->id);
        $name = $arguments->optionalString('name');
        $email = $arguments->optionalString('email');
        $username = $arguments->optionalString('username');

        if (null !== $name) {
            $update = $update->displayName($name);
        }

        if (null !== $email) {
            $update = $update->email($email);
        }

        if (null !== $username) {
            $update = $update->username($username);
        }

        return $this->writer->update($update);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:update', [$arguments->string('user')], ['name' => $arguments->optionalString('name'), 'email' => $arguments->optionalString('email'), 'username' => $arguments->optionalString('username')]);
    }

    private function user(string $reference): User
    {
        return $this->users->findByReference($reference) ?? throw UserNotFoundException::for($reference);
    }
}
