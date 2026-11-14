<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Themes;

/**
 * What a pinned site runs, kept in the database, so the site keeps running it when it stops pinning.
 *
 * While extensions are pinned (`quire.extensions.pinned: true`), the database's own record of the enabled
 * extensions and the theme isn't used, and a site installed pinned has none. Without this, removing the
 * pin would leave the site with no extensions enabled, not even the admin interface, and on the default
 * theme. So every time the site's migrations run while it's pinned, the pinned extensions and theme are
 * recorded; the first time they run after it stopped, they're enabled and chosen the way the admin
 * would (checked, with events and audit entries), and the record is removed.
 *
 * @internal
 */
final readonly class PinnedState
{
    public function __construct(
        private Connection $connection,
        private ExtensionManager $extensions,
        private ExtensionStateStoreInterface $state,
        private InstalledExtensions $installed,
        private Themes $themes,
        private AppRebuilderInterface $rebuilder,
    ) {
    }

    /**
     * Records the pinned extensions and theme.
     *
     * @param list<string> $enabled
     *
     * @return bool Whether the record changed
     */
    public function record(array $enabled, ?string $theme): bool
    {
        sort($enabled);

        try {
            $row = $this->connection->fetchAssociative('SELECT enabled, theme FROM quire.pinned_state WHERE id = 1');

            if (false !== $row && $this->ids($row['enabled']) === $enabled && $row['theme'] === $theme) {
                return false;
            }

            $this->connection->executeStatement(
                'INSERT INTO quire.pinned_state (id, enabled, theme, recorded_at) VALUES (1, ?::jsonb, ?, now())
                 ON CONFLICT (id) DO UPDATE SET enabled = EXCLUDED.enabled, theme = EXCLUDED.theme, recorded_at = EXCLUDED.recorded_at',
                [json_encode($enabled, \JSON_THROW_ON_ERROR), $theme],
            );
        } catch (TableNotFoundException) {
            // Its migration hasn't run (e.g. migrating to an earlier version).
            return false;
        }

        return true;
    }

    /**
     * After the site stopped pinning: enables the extensions it ran pinned, disables the others, chooses
     * its theme, rebuilds the application, and forgets the record. Nothing happens without a record.
     *
     * @param \Closure(string): void $output What was done, and what the rebuild prints
     *
     * @return bool Whether there was a record to carry over
     */
    public function carryOver(\Closure $output): bool
    {
        try {
            // Locked, so two machines starting at once carry it over once.
            $carried = $this->connection->transactional(function () use ($output): bool {
                $row = $this->connection->fetchAssociative('SELECT enabled, theme FROM quire.pinned_state WHERE id = 1 FOR UPDATE');

                if (false === $row) {
                    return false;
                }

                $this->apply($this->ids($row['enabled']), \is_string($row['theme']) ? $row['theme'] : null, $output);
                $this->connection->executeStatement('DELETE FROM quire.pinned_state WHERE id = 1');

                return true;
            });
        } catch (TableNotFoundException) {
            return false;
        }

        if ($carried) {
            // This process was built without them: their services, routes and templates need a new build.
            $this->rebuilder->rebuild(true, $output);
        }

        return $carried;
    }

    /**
     * @param list<string>           $pinned
     * @param \Closure(string): void $output
     */
    private function apply(array $pinned, ?string $theme, \Closure $output): void
    {
        $output("The site stopped pinning its extensions: what it ran pinned is enabled in the database.\n");
        $themes = array_keys($this->installed->themes());
        $missing = array_values(array_filter($pinned, fn (string $id): bool => !$this->installed->has($id)));

        if ([] !== $missing) {
            $output(\sprintf("Not installed any more, so not enabled: %s.\n", implode(', ', $missing)));
        }

        // With the extensions they require, as when enabled in the admin.
        $enabled = array_map(static fn (ExtensionManifest $manifest): string => $manifest->id, $this->extensions->enable(array_values(array_diff($pinned, $missing, $themes))));
        $disabled = $this->extensions->disable(array_values(array_diff($this->state->enabledIds(), $this->withRequirements([...$pinned, ...$enabled]), $themes)));
        $output(\sprintf("Enabled: %s. Disabled: %s.\n", [] === $enabled ? 'none' : implode(', ', $enabled), [] === $disabled ? 'none' : implode(', ', $disabled)));

        if ($this->themes->selected() === $theme) {
            return;
        }

        try {
            $this->themes->use($theme);
            $output(null === $theme ? "Theme: Quire's built-in templates.\n" : \sprintf("Theme: %s.\n", $theme));
        } catch (ThemeException $e) {
            $output(\sprintf("The theme it ran pinned, %s, can't be chosen: %s The site uses \"%s\"; choose one with \"bin/console quire:theme:use\".\n", $theme ?? 'Quire\'s built-in templates', $e->getMessage(), $this->themes->selected() ?? 'Quire\'s built-in templates'));
        }
    }

    /**
     * The extensions and those they require, and so on: none of them may be disabled while the others stay.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function withRequirements(array $ids): array
    {
        $all = $this->installed->all();
        $kept = [];

        while ([] !== $ids) {
            $id = array_pop($ids);

            if (isset($kept[$id])) {
                continue;
            }

            $kept[$id] = true;
            array_push($ids, ...($all[$id]->requires ?? []));
        }

        return array_keys($kept);
    }

    /**
     * Whether an installed, unpinned site may have lost what it ran pinned: no extension was ever enabled
     * or disabled in the database, and nothing is recorded to carry over.
     */
    public function mayHaveLostPinnedState(): bool
    {
        try {
            return false === $this->connection->fetchOne('SELECT 1 FROM quire.extension LIMIT 1')
                && false === $this->connection->fetchOne('SELECT 1 FROM quire.pinned_state LIMIT 1');
        } catch (TableNotFoundException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $json): array
    {
        $ids = \is_string($json) ? json_decode($json, true, flags: \JSON_THROW_ON_ERROR) : null;
        $ids = \is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
        sort($ids);

        return $ids;
    }
}
