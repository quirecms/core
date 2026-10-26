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
use Quire\Permission\Permissions;

/**
 * "role.grant": give a role abilities.
 *
 * @internal
 */
final readonly class GrantAbilities implements ActionInterface
{
    public function __construct(
        private Permissions $permissions,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Give a role abilities', [
            new Parameter('role', ParameterType::Role, 'Role'),
            new Parameter('abilities', ParameterType::Ability, 'Abilities', multiple: true),
        ], command: 'quire:permissions:grant');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::PERMISSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->permissions->grant($arguments->string('role'), $arguments->strings('abilities'));

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:permissions:grant', [$arguments->string('role'), ...$arguments->strings('abilities')]);
    }
}
