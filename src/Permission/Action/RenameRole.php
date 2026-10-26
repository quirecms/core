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
 * "role.rename": change what a role the site added is called. Its command is create-role, which renames
 * a role that exists.
 *
 * @internal
 */
final readonly class RenameRole implements ActionInterface
{
    public function __construct(
        private SiteRoles $roles,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Rename a role', [
            new Parameter('role', ParameterType::Role, 'Role'),
            new Parameter('label', ParameterType::Text, 'Name'),
        ], command: 'quire:permissions:create-role');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::PERMISSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->roles->relabel($arguments->string('role'), $arguments->string('label'));

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:permissions:create-role', [$arguments->string('role'), $arguments->string('label')]);
    }
}
