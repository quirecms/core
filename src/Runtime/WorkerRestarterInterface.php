<?php

declare(strict_types=1);

namespace Quire\Runtime;

/**
 * Restarts the long-running PHP workers on this machine, so they load the latest build.
 */
interface WorkerRestarterInterface
{
    /**
     * @return bool False when there are no workers to restart, e.g. when not running on FrankenPHP
     */
    public function restart(): bool;
}
