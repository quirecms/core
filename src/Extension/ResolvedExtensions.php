<?php

declare(strict_types=1);

namespace Quire\Extension;

/**
 * The outcome of resolving the enabled extensions against what's installed.
 *
 * @api
 */
final readonly class ResolvedExtensions
{
    /**
     * @param list<ExtensionManifest> $bootOrder Extensions that run, dependencies first
     * @param array<string, string>   $broken    Enabled and installed, but can't run: ID => reason
     * @param list<string>            $missing   Enabled, but no longer installed
     */
    public function __construct(
        public array $bootOrder,
        public array $broken = [],
        public array $missing = [],
    ) {
    }

    public function isRunning(string $id): bool
    {
        return array_any($this->bootOrder, static fn (ExtensionManifest $manifest): bool => $manifest->id === $id);
    }

    /**
     * Position in the boot order, starting at 1, or null when the extension doesn't run.
     */
    public function position(string $id): ?int
    {
        foreach ($this->bootOrder as $index => $manifest) {
            if ($manifest->id === $id) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function bootOrderIds(): array
    {
        return array_map(static fn (ExtensionManifest $manifest): string => $manifest->id, $this->bootOrder);
    }
}
