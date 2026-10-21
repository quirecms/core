<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Quire\Extension\Exception\ExtensionStateException;

/**
 * Remembers which extensions are enabled.
 *
 * It stores IDs only; deciding whether an enabled extension can actually run (installed,
 * dependencies satisfied) is the job of {@see \Quire\Extension\DependencyResolver}.
 */
interface ExtensionStateStoreInterface
{
    /**
     * @return list<string> IDs of the enabled extensions, sorted
     *
     * @throws ExtensionStateException
     */
    public function enabledIds(): array;

    /**
     * Why the enabled extensions can't be changed at runtime, or null when they can.
     */
    public function readOnlyReason(): ?string;

    /**
     * @param list<string> $ids
     *
     * @throws ExtensionStateException
     */
    public function enable(array $ids): void;

    /**
     * @param list<string> $ids
     *
     * @throws ExtensionStateException
     */
    public function disable(array $ids): void;
}
