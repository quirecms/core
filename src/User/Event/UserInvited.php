<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched after a user was emailed an invitation to choose their password.
 *
 * @api
 */
final readonly class UserInvited
{
    public function __construct(
        public User $user,
        /** Who invited them, when someone did */
        public ?User $by,
    ) {
    }
}
