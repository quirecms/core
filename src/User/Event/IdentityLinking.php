<?php

declare(strict_types=1);

namespace Quire\User\Event;

use Quire\Content\Event\CancellableEvent;
use Quire\User\User;

/**
 * Dispatched before an identity at another provider (an SSO account, a user of the host application) is
 * linked to a user, so they can sign in with it. Cancel it to refuse the link, e.g. for accounts of an
 * untrusted domain.
 *
 * Dispatched inside the transaction that links the identity.
 *
 * @api
 */
final class IdentityLinking extends CancellableEvent
{
    /**
     * @param string               $provider E.g. "google", or "host" for users of the host application
     * @param string               $subject  The identity's ID at the provider
     * @param array<string, mixed> $claims   What the provider says about the identity, e.g. email and name
     */
    public function __construct(
        public readonly User $user,
        public readonly string $provider,
        public readonly string $subject,
        public readonly array $claims,
    ) {
    }
}
