<?php

declare(strict_types=1);

namespace Quire\Permission\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Internal\Access;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Permission\SiteRoles;

/**
 * "role.delete": delete a role the site added: its grants, and the role from its users.
 *
 * @internal
 */
final readonly class DeleteRole implements ActionInterface
{
    public function __construct(
        private SiteRoles $roles,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Delete a role', [
            new Parameter('role', ParameterType::Role, 'Role'),
        ], command: 'quire:permissions:delete-role', destructive: true);
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::PERMISSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->roles->delete($arguments->string('role'));

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:permissions:delete-role', [$arguments->string('role')], ['force' => true]);
    }
}
