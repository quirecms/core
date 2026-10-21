<?php

declare(strict_types=1);

namespace Quire\Extension;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\UnknownExtensionException;

/**
 * Works out which enabled extensions can run, in which order, and what enabling or disabling would change.
 *
 * Pure logic: it takes manifests and IDs, and never reads the database or the filesystem.
 */
final class DependencyResolver
{
    /**
     * Resolves the enabled extensions into a boot order.
     *
     * An enabled extension doesn't run (it's "broken") when a required extension isn't installed, isn't
     * enabled, is itself broken, or when it's part of a dependency cycle. The rest of the site keeps working.
     *
     * Ordering: required and optional dependencies first, otherwise alphabetical by ID.
     *
     * @param array<string, ExtensionManifest> $installed  Keyed by ID
     * @param list<string>                     $enabledIds
     */
    public function resolve(array $installed, array $enabledIds): ResolvedExtensions
    {
        $missing = [];
        $candidates = [];

        foreach (array_unique($enabledIds) as $id) {
            if (isset($installed[$id])) {
                $candidates[$id] = $installed[$id];
            } else {
                $missing[] = $id;
            }
        }

        $broken = $this->findUnmetRequirements($installed, $candidates);
        $candidates = array_diff_key($candidates, $broken);

        [$bootOrder, $stuck] = $this->sort($candidates);

        if ([] !== $stuck) {
            $cycle = $this->cycleMembers($stuck);

            foreach ($cycle as $id) {
                $broken[$id] = \sprintf('it\'s part of a dependency cycle between "%s"', implode('", "', $cycle));
            }

            // The other stuck extensions require a cycle member, directly or through each other.
            $broken += $this->findUnmetRequirements($installed, array_diff_key($stuck, $broken), $broken);
        }

        ksort($broken);
        sort($missing);

        return new ResolvedExtensions($bootOrder, $broken, $missing);
    }

    /**
     * The extensions to enable so that all of $ids can run: $ids plus every required extension that
     * isn't enabled yet, in boot order.
     *
     * @param array<string, ExtensionManifest> $installed
     * @param list<string>                     $enabledIds
     * @param list<string>                     $ids
     *
     * @return list<ExtensionManifest>
     *
     * @throws UnknownExtensionException when one of $ids isn't installed
     * @throws DependencyException       when a required extension isn't installed, or the result can't run
     */
    public function planEnable(array $installed, array $enabledIds, array $ids): array
    {
        $toVisit = [];

        foreach ($ids as $id) {
            if (!isset($installed[$id])) {
                throw UnknownExtensionException::forId($id, array_keys($installed));
            }

            $toVisit[] = $id;
        }

        $needed = [];

        while ([] !== $toVisit) {
            $id = array_shift($toVisit);

            if (isset($needed[$id])) {
                continue;
            }

            $needed[$id] = true;

            foreach ($installed[$id]->requires as $requirement) {
                if (!isset($installed[$requirement])) {
                    throw new DependencyException(\sprintf('"%s" requires "%s", which isn\'t installed. Install it with Composer first.', $id, $requirement));
                }

                $toVisit[] = $requirement;
            }
        }

        $newIds = array_values(array_diff(array_keys($needed), $enabledIds));
        $after = $this->resolve($installed, [...$enabledIds, ...$newIds]);

        foreach ($newIds as $id) {
            if (isset($after->broken[$id])) {
                throw new DependencyException(\sprintf('"%s" can\'t be enabled because %s.', $id, $after->broken[$id]));
            }
        }

        return array_values(array_filter($after->bootOrder, static fn (ExtensionManifest $m): bool => \in_array($m->id, $newIds, true)));
    }

    /**
     * The extensions to disable: the enabled ones among $ids.
     *
     * @param array<string, ExtensionManifest> $installed
     * @param list<string>                     $enabledIds
     * @param list<string>                     $ids
     *
     * @return list<string> IDs that are currently enabled and will be disabled
     *
     * @throws UnknownExtensionException when an ID is neither installed nor enabled
     * @throws DependencyException       when an extension that stays enabled requires one of them
     */
    public function planDisable(array $installed, array $enabledIds, array $ids): array
    {
        foreach ($ids as $id) {
            if (!isset($installed[$id]) && !\in_array($id, $enabledIds, true)) {
                throw UnknownExtensionException::forId($id, array_keys($installed));
            }
        }

        $toDisable = array_values(array_intersect($enabledIds, $ids));
        $staying = array_diff($enabledIds, $toDisable);

        foreach ($staying as $id) {
            $requires = isset($installed[$id]) ? $installed[$id]->requires : [];
            $blocking = array_values(array_intersect($requires, $toDisable));

            if ([] !== $blocking) {
                throw new DependencyException(\sprintf('"%s" can\'t be disabled because "%s" requires it. Disable "%2$s" first, or disable both at once.', $blocking[0], $id));
            }
        }

        sort($toDisable);

        return $toDisable;
    }

    /**
     * Repeatedly marks candidates whose requirements aren't all available, until nothing changes.
     *
     * @param array<string, ExtensionManifest> $installed
     * @param array<string, ExtensionManifest> $candidates
     * @param array<string, string>            $alreadyBroken
     *
     * @return array<string, string> ID => reason
     */
    private function findUnmetRequirements(array $installed, array $candidates, array $alreadyBroken = []): array
    {
        $broken = [];

        do {
            $changed = false;

            foreach ($candidates as $id => $manifest) {
                if (isset($broken[$id])) {
                    continue;
                }

                foreach ($manifest->requires as $requirement) {
                    $reason = match (true) {
                        !isset($installed[$requirement]) => \sprintf('it requires "%s", which isn\'t installed', $requirement),
                        !isset($candidates[$requirement]) && !isset($alreadyBroken[$requirement]) => \sprintf('it requires "%s", which isn\'t enabled', $requirement),
                        isset($broken[$requirement]) || isset($alreadyBroken[$requirement]) => \sprintf('it requires "%s", which can\'t run', $requirement),
                        default => null,
                    };

                    if (null !== $reason) {
                        $broken[$id] = $reason;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        return $broken;
    }

    /**
     * Topological sort (Kahn's algorithm), taking the alphabetically first ready extension each time.
     *
     * Required dependencies are hard constraints. Optional ones only influence the order: when they
     * form a cycle, they're ignored for the extensions involved.
     *
     * @param array<string, ExtensionManifest> $candidates
     *
     * @return array{list<ExtensionManifest>, array<string, ExtensionManifest>} Boot order, and the
     *                                                                          extensions stuck behind a cycle of required dependencies
     */
    private function sort(array $candidates): array
    {
        $required = [];
        $optional = [];

        foreach ($candidates as $id => $manifest) {
            $required[$id] = array_values(array_filter($manifest->requires, static fn (string $d): bool => isset($candidates[$d])));
            $optional[$id] = array_values(array_filter($manifest->optional, static fn (string $d): bool => isset($candidates[$d])));
        }

        $order = [];

        while ([] !== $required) {
            $ready = array_keys(array_filter($required, static fn (array $deps, string $id): bool => [] === $deps && [] === $optional[$id], \ARRAY_FILTER_USE_BOTH));

            if ([] === $ready) {
                // Only optional dependencies are left in the way: ignore them rather than give up.
                $ready = array_keys(array_filter($required, static fn (array $deps): bool => [] === $deps));
            }

            if ([] === $ready) {
                return [$order, array_intersect_key($candidates, $required)];
            }

            sort($ready);
            $next = $ready[0];
            $order[] = $candidates[$next];
            unset($required[$next], $optional[$next]);

            foreach (array_keys($required) as $id) {
                $required[$id] = array_values(array_diff($required[$id], [$next]));
                $optional[$id] = array_values(array_diff($optional[$id], [$next]));
            }
        }

        return [$order, []];
    }

    /**
     * The stuck extensions that are actually part of a cycle: those that can reach themselves by
     * following required dependencies.
     *
     * @param array<string, ExtensionManifest> $stuck
     *
     * @return list<string>
     */
    private function cycleMembers(array $stuck): array
    {
        $members = [];

        foreach (array_keys($stuck) as $start) {
            $toVisit = $stuck[$start]->requires;
            $seen = [];

            while ([] !== $toVisit) {
                $id = array_shift($toVisit);

                if ($id === $start) {
                    $members[] = $start;
                    break;
                }

                if (isset($seen[$id]) || !isset($stuck[$id])) {
                    continue;
                }

                $seen[$id] = true;
                array_push($toVisit, ...$stuck[$id]->requires);
            }
        }

        sort($members);

        return $members;
    }
}
