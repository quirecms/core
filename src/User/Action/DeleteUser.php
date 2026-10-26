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
 * "user.delete": delete a user, giving their content to another user or keeping it without an author.
 *
 * @internal
 */
final readonly class DeleteUser implements ActionInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Delete a user', [
            new Parameter('user', ParameterType::User, 'User'),
            new Parameter('reassign', ParameterType::User, 'Who gets their content', required: false),
        ], command: 'quire:user:delete', destructive: true);
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::USERS);
    }

    public function run(Arguments $arguments): ?object
    {
        $reassign = $arguments->optionalString('reassign');
        $this->writer->delete($this->user($arguments->string('user'))->id, null === $reassign ? null : $this->user($reassign)->id);

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:user:delete', [$arguments->string('user')], ['reassign' => $arguments->optionalString('reassign'), 'keep-content' => $arguments->has('reassign') ? null : true]);
    }

    private function user(string $reference): User
    {
        return $this->users->findByReference($reference) ?? throw UserNotFoundException::for($reference);
    }
}
