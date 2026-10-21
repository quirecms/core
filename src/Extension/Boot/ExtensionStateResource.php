<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;

/**
 * Marks the cached container as stale when the enabled extensions in the database change.
 *
 * Symfony checks resources in debug mode only, so in development enabling or disabling an extension
 * rebuilds the container automatically on the next boot.
 *
 * @internal
 */
final readonly class ExtensionStateResource implements SelfCheckingResourceInterface
{
    public function __construct(
        private string $hash,
    ) {
    }

    public function __toString(): string
    {
        return 'quire_extension_state';
    }

    public function isFresh(int $timestamp): bool
    {
        $current = CompileTimeState::read();

        // If the database is down now, keep the container rather than rebuild it without extensions.
        return !$current->available || $current->hash() === $this->hash;
    }
}
