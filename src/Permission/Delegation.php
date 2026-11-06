<?php

declare(strict_types=1);

namespace Quire\Permission;

use Quire\User\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Whether someone may give out, or act on, abilities: nobody hands out what they don't hold, and nobody
 * manages a user who holds something they don't (D-42). "Holds" is what {@see Access} says: their roles,
 * the site's grants and extensions' rules, as is_granted() decides it.
 *
 * Every path that gives out power asks it: granting abilities to a role, giving users roles, changing a
 * user's account, writing the permission settings. The command line doesn't: whoever runs bin/console
 * runs the site, and strict abilities are granted there.
 *
 *     $delegation->missing($actor, ['quire.extensions.manage']);   // what of these they lack
 *     $delegation->rolesBeyond($actor, ['admin']);                 // roles they may not give
 *     $delegation->beyond($actor, $kim);                           // what Kim holds and they don't
 *
 * @api
 */
final readonly class Delegation
{
    public function __construct(
        private Permissions $permissions,
        private Access $access,
    ) {
    }

    /**
     * The abilities of the list the actor doesn't hold.
     *
     * @param iterable<string> $abilities
     *
     * @return list<string>
     */
    public function missing(UserInterface $actor, iterable $abilities): array
    {
        $access = $this->access->for($actor);
        $missing = [];

        foreach ($abilities as $ability) {
            if (!\in_array($ability, $missing, true) && !$access->allows($ability)) {
                $missing[] = $ability;
            }
        }

        return $missing;
    }

    /**
     * The roles of the list that give an ability the actor doesn't hold, with those abilities.
     *
     * @param iterable<string> $roles
     *
     * @return array<string, list<string>> Role => abilities the actor lacks
     */
    public function rolesBeyond(UserInterface $actor, iterable $roles): array
    {
        $beyond = [];

        foreach ($roles as $role) {
            $missing = $this->missing($actor, $this->abilitiesOf([$role]));

            if ([] !== $missing) {
                $beyond[$role] = $missing;
            }
        }

        return $beyond;
    }

    /**
     * The abilities the target holds and the actor doesn't: the actor may manage the target only when
     * there are none.
     *
     * @return list<string>
     */
    public function beyond(UserInterface $actor, User $target): array
    {
        $theirs = $this->access->for($target);

        return $this->missing($actor, array_filter(array_keys($this->permissions->abilities()), $theirs->allows(...)));
    }

    /**
     * A sentence that says why, naming the first few abilities: for refusals.
     *
     * @param list<string> $abilities
     */
    public function labels(array $abilities): string
    {
        $labels = array_map(fn (string $ability): string => '"'.($this->permissions->abilities()[$ability]['label'] ?? $ability).'"', \array_slice($abilities, 0, 3));

        return implode(', ', $labels).(\count($abilities) > 3 ? \sprintf(' and %d more', \count($abilities) - 3) : '');
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function abilitiesOf(array $roles): array
    {
        return array_values(array_filter(array_keys($this->permissions->abilities()), fn (string $ability): bool => $this->permissions->allows($roles, $ability)));
    }
}
