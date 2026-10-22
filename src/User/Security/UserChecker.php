<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Quire\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses suspended users, whichever way they sign in. Use it as a firewall's "user_checker".
 *
 * The check runs after the credentials were checked, so only someone who knows the password learns that
 * the account is suspended; everyone else gets the usual "Invalid credentials".
 *
 * @api
 */
final readonly class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account is suspended.');
        }
    }
}
