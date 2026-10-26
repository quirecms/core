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
 * "role.create": add a role to the site, optionally with another role's abilities.
 *
 * @internal
 */
final readonly class CreateRole implements ActionInterface
{
    public function __construct(
        private SiteRoles $roles,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Add a role', [
            new Parameter('role', ParameterType::Role, 'Role'),
            new Parameter('label', ParameterType::Text, 'Name'),
            new Parameter('from', ParameterType::Role, 'Starts with the abilities of', required: false),
        ], command: 'quire:permissions:create-role');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::PERMISSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->roles->create($arguments->string('role'), $arguments->string('label'), $arguments->optionalString('from'));

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:permissions:create-role', [$arguments->string('role'), $arguments->string('label')], ['from' => $arguments->optionalString('from')]);
    }
}
