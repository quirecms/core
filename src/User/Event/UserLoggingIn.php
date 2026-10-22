<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\Content\Event\CancellableEvent;
use Quire\User\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched when a user is about to sign in, however they sign in, before their credentials are checked.
 * Cancel it to refuse the sign-in; the reason is shown on the sign-in page.
 *
 *     #[AsEventListener]
 *     public function __invoke(UserLoggingIn $event): void
 *     {
 *         if ($this->maintenance->isOn() && !$event->user->hasRole(Role::ADMIN)) {
 *             $event->cancel('Only admins can sign in during maintenance.');
 *         }
 *     }
 *
 * @api
 */
final class UserLoggingIn extends CancellableEvent
{
    /**
     * @param string $firewall The name of the Symfony firewall, e.g. "main"
     */
    public function __construct(
        public readonly User $user,
        public readonly Request $request,
        public readonly string $firewall,
    ) {
    }
}
