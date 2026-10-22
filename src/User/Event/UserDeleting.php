<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched before a user is deleted, inside the transaction that deletes them, so extensions can deal
 * with what they keep about the user: give it to $reassignTo when there is one, or delete or anonymise it.
 *
 * Their content has been dealt with already: given to $reassignTo, or kept without an author.
 *
 * @api
 */
final readonly class UserDeleting
{
    public function __construct(
        public User $user,
        public ?User $reassignTo,
    ) {
    }
}
