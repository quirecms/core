<?php

declare(strict_types=1);

namespace Quire\Extension;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;

/**
 * Enables and disables extensions, and rebuilds the application for them: what quire:extension:enable
 * and quire:extension:disable do, for admin interfaces too. Plan first with
 * {@see ExtensionManager::planEnable()} and {@see ExtensionManager::planDisable()}, e.g. to ask whether
 * the extensions that are required too may be enabled.
 *
 * @api
 */
final readonly class ExtensionSwitcher
{
    public function __construct(
        private ExtensionManager $extensions,
        private ExtensionPurgerInterface $purger,
        private AppRebuilderInterface $rebuilder,
    ) {
    }

    /**
     * Enables the extensions and those they require, rebuilds the application and runs their migrations.
     * When that fails, they're disabled again, so none is left enabled without its migrations.
     *
     * @param list<string>           $ids
     * @param \Closure(string): void $output What the rebuild prints
     *
     * @return list<string> The IDs of the extensions enabled, in order; empty when all were already
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException
     * @throws RebuildFailedException    after they were disabled again
     */
    public function enable(array $ids, \Closure $output): array
    {
        $plan = array_map(static fn (ExtensionManifest $manifest): string => $manifest->id, $this->extensions->enable($ids));

        if ([] === $plan) {
            return [];
        }

        $output("Rebuilding the application and running migrations…\n");

        try {
            $this->rebuilder->rebuild(true, $output);
        } catch (RebuildFailedException $e) {
            $this->extensions->disable($plan);
            $this->rebuilder->rebuild(false, $output);

            throw new RebuildFailedException(\sprintf('%s The extensions were disabled again: %s.', $e->getMessage(), implode(', ', $plan)), $e->getCode(), previous: $e);
        }

        return $plan;
    }

    /**
     * Disables the extensions and those that require them, and rebuilds the application.
     *
     * @param list<string>           $ids
     * @param bool                   $purge  Also delete their data for good: roll back their migrations and delete their settings
     * @param \Closure(string): void $output What the purge and the rebuild print
     *
     * @return list<string> The IDs of the extensions disabled; empty when none was enabled
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException   also when purging extensions that aren't running
     * @throws RebuildFailedException
     */
    public function disable(array $ids, bool $purge, \Closure $output): array
    {
        $plan = $this->extensions->planDisable($ids);

        if ([] === $plan) {
            return [];
        }

        if ($purge) {
            $problem = $this->purgeProblem($plan);

            if (null !== $problem) {
                throw new ExtensionStateException($problem);
            }

            // Dependents first, so nothing outlives what it requires.
            $this->purger->purge(array_reverse(array_values(array_filter($this->extensions->resolve()->bootOrder, static fn (ExtensionManifest $m): bool => \in_array($m->id, $plan, true)))), $output);
        }

        $this->extensions->disable($plan);
        $output("Rebuilding the application…\n");
        $this->rebuilder->rebuild(false, $output);

        return $plan;
    }

    /**
     * Why these extensions' data can't be purged, or null when it can: only running extensions can, because
     * their migrations must be loaded to roll them back.
     *
     * @param list<string> $ids As planned by {@see ExtensionManager::planDisable()}
     */
    public function purgeProblem(array $ids): ?string
    {
        $resolved = $this->extensions->resolve();
        $notRunning = array_values(array_filter($ids, static fn (string $id): bool => !$resolved->isRunning($id)));

        return [] === $notRunning ? null : \sprintf('Only running extensions can be purged, because their migrations must be loaded to roll them back. Not running: %s. Fix the problems shown by "quire:extension:list", or disable without purging.', implode(', ', $notRunning));
    }
}
