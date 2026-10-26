<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched after a user was changed: their profile, roles, status (suspended or active), password,
 * or email confirmation. Compare $before with $user to see what changed.
 *
 * @api
 */
final readonly class UserUpdated
{
    public function __construct(
        public User $before,
        public User $user,
    ) {
    }
}
