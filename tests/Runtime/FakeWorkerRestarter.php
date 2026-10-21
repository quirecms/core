<?php

declare(strict_types=1);

namespace Quire\Tests\Runtime;

use Quire\Runtime\WorkerRestarterInterface;

final class FakeWorkerRestarter implements WorkerRestarterInterface
{
    public int $restarts = 0;

    public function __construct(
        private readonly bool $hasWorkers = true,
    ) {
    }

    public function restart(): bool
    {
        ++$this->restarts;

        return $this->hasWorkers;
    }
}
