<?php

declare(strict_types=1);

namespace Quire\Settings;

use Quire\Permission\Delegation;
use Quire\Permission\Permissions;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Whether a person may change a setting (D-42): managing settings isn't a way around the rest of the
 * admin. A setting another part of it owns takes that part's ability too (the active theme takes
 * "quire.themes.manage"), and the permission settings change only what the person could change on the
 * Roles page: abilities they hold themselves.
 *
 * Every path that writes settings for a person asks it: the import, the settings form and its "back to
 * the default", "setting.set" and its undo. The command line doesn't: it runs the site.
 *
 * @api
 */
final readonly class SettingAccess
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
        private Permissions $permissions,
        private Delegation $delegation,
    ) {
    }

    /**
     * Why the person may not set the setting to the value, or null when they may.
     */
    public function refusal(UserInterface $actor, string $key, mixed $value): ?string
    {
        $ability = $this->schema->has($key) ? $this->schema->get($key)->ability : null;

        if (null !== $ability && [] !== $this->delegation->missing($actor, [$ability])) {
            return \sprintf('Changing it takes the right %s, which you don\'t have.', $this->delegation->labels([$ability]));
        }

        $touched = match ($key) {
            Permissions::SETTING => $this->changedAbilities($value),
            Permissions::ROLES_SETTING => $this->abilitiesOfRemovedRoles($value),
            default => [],
        };
        $missing = [] === $touched ? [] : $this->delegation->missing($actor, $touched);

        return [] === $missing ? null : \sprintf('It gives or takes away %s, which you don\'t have yourself.', $this->delegation->labels($missing));
    }

    /**
     * The refusals of several changes, by key: none when they may make them all.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    public function refusals(UserInterface $actor, array $values): array
    {
        $refusals = [];

        foreach ($values as $key => $value) {
            $refusal = $this->refusal($actor, $key, $value);

            if (null !== $refusal) {
                $refusals[$key] = $refusal;
            }
        }

        return $refusals;
    }

    /**
     * The abilities whose roles the value changes, against the current one.
     *
     * @return list<string>
     */
    private function changedAbilities(mixed $value): array
    {
        $now = $this->permissions->grantsFrom($this->settings->get(Permissions::SETTING));
        $then = $this->permissions->grantsFrom($value);
        $changed = [];

        foreach ($then as $ability => $roles) {
            $before = $now[$ability] ?? [];
            sort($before);
            sort($roles);

            if ($before !== $roles) {
                $changed[] = $ability;
            }
        }

        return $changed;
    }

    /**
     * The abilities of the site's roles the value takes away: their holders lose them.
     *
     * @return list<string>
     */
    private function abilitiesOfRemovedRoles(mixed $value): array
    {
        $kept = \is_array($value) ? array_keys($value) : [];
        $removed = array_diff(array_keys($this->permissions->siteRoles()), $kept);

        return array_values(array_filter(array_keys($this->permissions->abilities()), fn (string $ability): bool => [] !== $removed && $this->permissions->allows(array_values($removed), $ability)));
    }
}
