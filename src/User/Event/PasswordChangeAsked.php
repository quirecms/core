<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;

/**
 * Dispatched after a user was emailed a link to choose a new password, as someone who manages them
 * asked (see {@see \Quire\User\Invitations::askForNewPassword()}).
 *
 * @api
 */
final readonly class PasswordChangeAsked
{
    public function __construct(
        public User $user,
        /** Who asked, when someone did */
        public ?User $by,
    ) {
    }
}
