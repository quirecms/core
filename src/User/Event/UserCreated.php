<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched after a user was created, e.g. by an admin, on the command line, or when someone registered
 * (then {@see UserRegistered} follows).
 *
 * @api
 */
final readonly class UserCreated
{
    public function __construct(
        public User $user,
    ) {
    }
}
