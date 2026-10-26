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
 * "user.set_password": give a user a new password. Its command asks for it: passwords are never on a command line.
 *
 * @internal
 */
final readonly class SetPassword implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Set a user\'s password', [
            new Parameter('user', ParameterType::User, 'User'),
            new Parameter('password', ParameterType::Text, 'Password', secret: true),
        ], command: 'quire:user:password');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): User
    {
        return $this->writer->changePassword($this->user($arguments->string('user'))->id, $arguments->string('password'));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:password', [$arguments->string('user')]);
    }

    private function user(string $reference): User
    {
        return $this->users->findByReference($reference) ?? throw UserNotFoundException::for($reference);
    }
}
