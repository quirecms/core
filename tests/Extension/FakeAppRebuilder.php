<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;

/**
 * Records rebuilds instead of running console commands; can be told to fail once.
 */
final class FakeAppRebuilder implements AppRebuilderInterface
{
    /** @var list<string> "migrate" or "no-migrate", one per call */
    public array $calls = [];

    public bool $failNext = false;

    public function rebuild(bool $migrate, \Closure $output): void
    {
        $this->calls[] = $migrate ? 'migrate' : 'no-migrate';

        if ($this->failNext) {
            $this->failNext = false;

            throw new RebuildFailedException('"bin/console doctrine:migrations:migrate" failed with exit code 1.');
        }
    }
}
