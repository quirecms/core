<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;

final class FixtureAction implements ActionInterface
{
    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Approve a review');
    }

    public function denied(Arguments $arguments): ?string
    {
        return null;
    }

    public function run(Arguments $arguments): null
    {
        return null;
    }

    public function commandLine(Arguments $arguments): null
    {
        return null;
    }
}
