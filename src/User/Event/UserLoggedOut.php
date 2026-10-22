<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched after a user signed out.
 *
 * @api
 */
final readonly class UserLoggedOut
{
    public function __construct(
        public User $user,
        public Request $request,
    ) {
    }
}
