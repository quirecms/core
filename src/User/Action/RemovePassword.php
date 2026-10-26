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
 * "user.remove_password": take a user's password, for users who sign in another way.
 *
 * @internal
 */
final readonly class RemovePassword implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Remove a user\'s password', [
            new Parameter('user', ParameterType::User, 'User'),
        ], command: 'quire:user:password');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): User
    {
        return $this->writer->changePassword($this->user($arguments->string('user'))->id, null);
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:password', [$arguments->string('user')], ['remove' => true]);
    }

    private function user(string $reference): User
    {
        return $this->users->findByReference($reference) ?? throw UserNotFoundException::for($reference);
    }
}
