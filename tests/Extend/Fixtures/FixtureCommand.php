<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'acme:fixture')]
final class FixtureCommand
{
    public function __invoke(): int
    {
        return 0;
    }
}
