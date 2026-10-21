<?php

declare(strict_types=1);

namespace Quire\Extension\Rebuild;

/**
 * Brings the application up to date after the enabled extensions changed.
 */
interface AppRebuilderInterface
{
    /**
     * @param bool                   $migrate Also run pending database migrations
     * @param \Closure(string): void $output  Receives progress output
     *
     * @throws RebuildFailedException
     */
    public function rebuild(bool $migrate, \Closure $output): void;
}
