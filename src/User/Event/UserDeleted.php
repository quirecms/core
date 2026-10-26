<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched after a user was deleted. {@see UserDeleting} came before, inside the transaction.
 *
 * @api
 */
final readonly class UserDeleted
{
    public function __construct(
        public User $user,
        /** Who got their content; null when it's kept without an author */
        public ?User $reassignTo,
    ) {
    }
}
