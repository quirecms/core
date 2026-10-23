<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses suspended users, and, when emails must be confirmed, users who haven't confirmed theirs,
 * whichever way they sign in. Use it as a firewall's "user_checker".
 *
 * The checks run after the credentials were checked, so only someone who knows the password learns about
 * the account; everyone else gets the usual "Invalid credentials".
 *
 * @api
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public const string UNVERIFIED_MESSAGE = 'Confirm your email address first: open the link we emailed you.';

    public function __construct(
        private Settings $settings,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account is suspended.');
        }

        // Users without an email sign in some other way, e.g. with single sign-on.
        if (null !== $user->email && !$user->hasVerifiedEmail() && $this->settings->getBool(AccountSettings::EMAIL_VERIFICATION)) {
            throw new CustomUserMessageAccountStatusException(self::UNVERIFIED_MESSAGE);
        }
    }
}
