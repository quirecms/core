<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\User\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched after a visitor created an account on the registration page.
 *
 * @api
 */
final readonly class UserRegistered
{
    public function __construct(
        public User $user,
        public Request $request,
    ) {
    }
}
