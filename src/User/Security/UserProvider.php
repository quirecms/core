<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads Quire's users for Symfony's security: by ID (what sessions and remember-me cookies store), or by
 * the email or username someone signs in with.
 *
 * Available as the security user provider "quire_users".
 *
 * @implements UserProviderInterface<User>
 *
 * @api
 */
final readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        return $this->users->findByReference($identifier) ?? throw $this->notFound($identifier);
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" aren\'t Quire users.', $user::class));
        }

        // The user may have changed since. Deleted and suspended users are signed out.
        $current = $this->users->find($user->id);

        if (!$current instanceof User || !$current->isActive()) {
            throw $this->notFound($user->id);
        }

        return $current;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    /**
     * Stores the stronger hash Symfony makes at sign-in when the stored one is outdated.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof User) {
            $this->writer->storePasswordHash($user->id, $newHashedPassword);
        }
    }

    private function notFound(string $identifier): UserNotFoundException
    {
        $exception = new UserNotFoundException(\sprintf('No user "%s".', $identifier));
        $exception->setUserIdentifier($identifier);

        return $exception;
    }
}
