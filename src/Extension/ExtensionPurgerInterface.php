<?php

declare(strict_types=1);

namespace Quire\Extension;

/**
 * Permanently deletes extensions' data.
 */
interface ExtensionPurgerInterface
{
    /**
     * @param list<ExtensionManifest> $extensions In the order to purge them: dependents first
     * @param \Closure(string): void  $output
     */
    public function purge(array $extensions, \Closure $output): void;
}
