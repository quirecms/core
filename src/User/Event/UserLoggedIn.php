<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched after a user signed in, e.g. with a password, a "remember me" cookie or single sign-on.
 *
 * @api
 */
final readonly class UserLoggedIn
{
    /**
     * @param string $firewall The name of the Symfony firewall, e.g. "main"
     */
    public function __construct(
        public User $user,
        public Request $request,
        public string $firewall,
    ) {
    }
}
