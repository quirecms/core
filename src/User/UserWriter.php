<?php

declare(strict_types=1);

namespace Quire\User;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Quire\Content\Internal\Slugs;
use Quire\Permission\Permissions;
use Quire\User\Event\IdentityLinking;
use Quire\User\Event\UserDeleting;
use Quire\User\Exception\IdentityLinkRefusedException;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Exception\UserNotFoundException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates and changes users. Every change of a user goes through here, so the rules hold: emails and
 * usernames are unique, passwords are only stored hashed, and roles are known.
 *
 * @api
 */
final readonly class UserWriter
{
    public const int PASSWORD_MIN_LENGTH = 8;

    /** Longer passwords are refused rather than hashed, so they can't be used to exhaust the server */
    public const int PASSWORD_MAX_LENGTH = 4096;

    private const int USERNAME_MAX_LENGTH = 64;

    private const int DISPLAY_NAME_MAX_LENGTH = 100;

    public function __construct(
        private Connection $connection,
        private UserRepository $users,
        private PasswordHasherFactoryInterface $hashers,
        private Permissions $permissions,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @throws InvalidUserException
     */
    public function create(CreateUser $command): User
    {
        $violations = [];
        $displayName = $this->displayName($command->displayName, $violations);
        $email = $this->email($command->email, $violations);
        $roles = $this->roles($command->roles, $violations);
        $hash = null === $command->password ? null : $this->hash($command->password, $violations);
        $username = null === $command->username ? null : $this->username($command->username, $violations);

        if ([] !== $violations) {
            throw new InvalidUserException($violations);
        }

        return $this->transactional(function () use ($command, $displayName, $email, $roles, $hash, $username): User {
            $this->lockUsernames();

            if (null === $username) {
                $username = $this->freeUsername($this->usernameFrom($command->email ?? $displayName));
            } elseif ($this->usernameTaken($username)) {
                throw InvalidUserException::single('username', \sprintf('The username "%s" is taken.', $username));
            }

            $id = $this->connection->fetchOne(
                'INSERT INTO quire.app_user (email, username, display_name, password_hash, roles, attributes) VALUES (?, ?, ?, ?, ?::text[], ?::jsonb) RETURNING id',
                [$email, $username, $displayName, $hash, $this->array($roles), json_encode((object) $command->attributes, \JSON_THROW_ON_ERROR)],
            );

            return $this->get(\is_string($id) ? $id : throw new \LogicException('The new user has no ID.'));
        });
    }

    /**
     * @throws InvalidUserException
     * @throws UserNotFoundException
     */
    public function update(UpdateUser $command): User
    {
        $this->get($command->id);
        $violations = [];
        $columns = [];

        foreach ($command->changes as $property => $value) {
            $columns[$property] = match ($property) {
                'display_name' => $this->displayName(\is_string($value) ? $value : '', $violations),
                'email' => $this->email(\is_string($value) ? $value : null, $violations),
                'username' => $this->username(\is_string($value) ? $value : '', $violations),
                'attributes' => json_encode((object) $value, \JSON_THROW_ON_ERROR),
                default => throw new \LogicException(\sprintf('Unknown property "%s".', $property)),
            };
        }

        if ([] !== $violations) {
            throw new InvalidUserException($violations);
        }

        return $this->transactional(function () use ($command, $columns): User {
            if (isset($columns['username'])) {
                $this->lockUsernames();

                if ($this->usernameTaken($columns['username'], $command->id)) {
                    throw InvalidUserException::single('username', \sprintf('The username "%s" is taken.', $columns['username']));
                }
            }

            $this->set($command->id, $columns);

            return $this->get($command->id);
        });
    }

    /**
     * @param string|null $password In plain text; null removes the password, e.g. for SSO-only users
     *
     * @throws InvalidUserException
     * @throws UserNotFoundException
     */
    public function changePassword(string $id, #[\SensitiveParameter] ?string $password): User
    {
        $this->get($id);
        $violations = [];
        $hash = null === $password ? null : $this->hash($password, $violations);

        if ([] !== $violations) {
            throw new InvalidUserException($violations);
        }

        $this->set($id, ['password_hash' => $hash]);

        return $this->get($id);
    }

    /**
     * Stores a hash made elsewhere, e.g. a stronger one Symfony made from the password at sign-in.
     *
     * @internal
     */
    public function storePasswordHash(string $id, string $hash): void
    {
        $this->set($id, ['password_hash' => $hash]);
    }

    /**
     * @param list<string> $roles The complete list, e.g. ["editor"]
     *
     * @throws InvalidUserException
     * @throws UserNotFoundException
     */
    public function setRoles(string $id, array $roles): User
    {
        $this->get($id);
        $violations = [];
        $roles = $this->roles($roles, $violations);

        if ([] !== $violations) {
            throw new InvalidUserException($violations);
        }

        $this->connection->executeStatement('UPDATE quire.app_user SET roles = ?::text[], updated_at = now() WHERE id = ?', [$this->array($roles), $id]);

        return $this->get($id);
    }

    /**
     * A suspended user can't sign in, and is signed out on their next request.
     *
     * @throws UserNotFoundException
     */
    public function suspend(string $id): User
    {
        return $this->setStatus($id, User::SUSPENDED);
    }

    /**
     * @throws UserNotFoundException
     */
    public function activate(string $id): User
    {
        return $this->setStatus($id, User::ACTIVE);
    }

    public function recordLogin(string $id): void
    {
        $this->connection->executeStatement('UPDATE quire.app_user SET last_login_at = now() WHERE id = ?', [$id]);
    }

    /**
     * Links an identity at another provider, e.g. a Google account, to the user. Linking it again
     * updates its claims.
     *
     * @param array<string, mixed> $claims What the provider says about the identity
     *
     * @throws InvalidUserException         when the identity belongs to another user
     * @throws IdentityLinkRefusedException when a listener of IdentityLinking refuses it
     */
    public function linkIdentity(string $id, string $provider, string $subject, array $claims = []): void
    {
        $this->transactional(function () use ($id, $provider, $subject, $claims): void {
            $user = $this->get($id);
            $owner = $this->users->findByIdentity($provider, $subject);

            if ($owner instanceof User && $owner->id !== $id) {
                throw InvalidUserException::single('identity', \sprintf('This %s identity is linked to another user.', $provider));
            }

            if (!$owner instanceof User) {
                $event = new IdentityLinking($user, $provider, $subject, $claims);
                $this->dispatcher->dispatch($event);

                if ($event->isCancelled()) {
                    throw new IdentityLinkRefusedException($event->cancelReason() ?? 'Linking the identity was refused.');
                }
            }

            // Linked to another user meanwhile: nothing changes, and that's refused below.
            $linked = $this->connection->executeStatement(
                'INSERT INTO quire.user_identity (user_id, provider, subject, claims) VALUES (?, ?, ?, ?::jsonb)
                 ON CONFLICT (provider, subject) DO UPDATE SET claims = EXCLUDED.claims, last_used_at = now()
                 WHERE quire.user_identity.user_id = EXCLUDED.user_id',
                [$id, $provider, $subject, json_encode((object) $claims, \JSON_THROW_ON_ERROR)],
            );

            if (0 === $linked) {
                throw InvalidUserException::single('identity', \sprintf('This %s identity is linked to another user.', $provider));
            }
        });
    }

    /**
     * Keeps what the provider says about a linked identity up to date, and when it was last used.
     *
     * @param array<string, mixed> $claims
     */
    public function updateIdentity(string $provider, string $subject, array $claims): void
    {
        $this->connection->executeStatement(
            'UPDATE quire.user_identity SET claims = ?::jsonb, last_used_at = now() WHERE provider = ? AND subject = ?',
            [json_encode((object) $claims, \JSON_THROW_ON_ERROR), $provider, $subject],
        );
    }

    /**
     * Deletes a user. Their content goes to $reassignTo, or, with null, stays without an author; either
     * way, revisions forget who made them. Extensions deal with their data on {@see UserDeleting}.
     *
     * @param string|null $reassignTo The ID of the user who gets their content
     *
     * @throws UserNotFoundException
     * @throws InvalidUserException  When $reassignTo is the user
     */
    public function delete(string $id, ?string $reassignTo = null): void
    {
        $this->transactional(function () use ($id, $reassignTo): void {
            $user = $this->get($id);
            $heir = null === $reassignTo ? null : $this->get($reassignTo);

            if ($heir?->id === $user->id) {
                throw InvalidUserException::single('reassignTo', 'Choose another user to give the content to.');
            }

            $this->connection->executeStatement('UPDATE quire.content SET author_id = ? WHERE author_id = ?', [$heir?->id, $user->id]);
            $this->dispatcher->dispatch(new UserDeleting($user, $heir));
            $this->connection->executeStatement('DELETE FROM quire.app_user WHERE id = ?', [$user->id]);
        });
    }

    /**
     * How many content items the user is the author of, trashed ones included.
     */
    public function contentCount(string $id): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM quire.content WHERE author_id = ?', [$id]);

        return \is_int($count) ? $count : (int) (\is_string($count) ? $count : 0);
    }

    public function unlinkIdentity(string $id, string $provider, string $subject): void
    {
        $this->connection->executeStatement('DELETE FROM quire.user_identity WHERE user_id = ? AND provider = ? AND subject = ?', [$id, $provider, $subject]);
    }

    private function setStatus(string $id, string $status): User
    {
        $this->get($id);
        $this->set($id, ['status' => $status]);

        return $this->get($id);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function set(string $id, array $columns): void
    {
        if ([] === $columns) {
            return;
        }

        $assignments = array_map(static fn (string $column): string => 'attributes' === $column ? $column.' = ?::jsonb' : $column.' = ?', array_keys($columns));

        try {
            $this->connection->executeStatement(
                \sprintf('UPDATE quire.app_user SET %s, updated_at = now() WHERE id = ?', implode(', ', $assignments)),
                [...array_values($columns), $id],
            );
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }
    }

    private function get(string $id): User
    {
        return $this->users->find($id) ?? throw UserNotFoundException::for($id);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        try {
            return $this->connection->transactional($operation);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }
    }

    private function duplicate(UniqueConstraintViolationException $e): InvalidUserException
    {
        return str_contains($e->getMessage(), 'app_user_email_idx')
            ? InvalidUserException::single('email', 'Another user has this email.')
            : InvalidUserException::single('username', 'Another user has this username.');
    }

    /**
     * @param array<string, string> $violations
     */
    private function displayName(string $displayName, array &$violations): string
    {
        $displayName = trim($displayName);

        if ('' === $displayName || mb_strlen($displayName) > self::DISPLAY_NAME_MAX_LENGTH) {
            $violations['displayName'] = \sprintf('The display name must be 1 to %d characters long.', self::DISPLAY_NAME_MAX_LENGTH);
        }

        return $displayName;
    }

    /**
     * @param array<string, string> $violations
     */
    private function email(?string $email, array &$violations): ?string
    {
        if (null === $email) {
            return null;
        }

        $email = trim($email);

        if (\strlen($email) > 254 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $violations['email'] = \sprintf('"%s" isn\'t a valid email address.', $email);
        }

        return $email;
    }

    /**
     * @param array<string, string> $violations
     */
    private function username(string $username, array &$violations): string
    {
        $username = strtolower(trim($username));

        if (\strlen($username) > self::USERNAME_MAX_LENGTH || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $username)) {
            $violations['username'] = \sprintf('Usernames are lowercase letters, digits and single hyphens, at most %d characters, e.g. "ada-lovelace".', self::USERNAME_MAX_LENGTH);
        }

        return $username;
    }

    /**
     * @param list<string>          $roles
     * @param array<string, string> $violations
     *
     * @return list<string>
     */
    private function roles(array $roles, array &$violations): array
    {
        $known = array_keys(array_diff_key($this->permissions->roles(), [Role::GUEST => true]));
        $unknown = array_diff($roles, $known);

        if ([] !== $unknown) {
            $violations['roles'] = \sprintf('Unknown role "%s". Roles: %s.', reset($unknown), implode(', ', $known));
        }

        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    /**
     * @param array<string, string> $violations
     */
    private function hash(#[\SensitiveParameter] string $password, array &$violations): ?string
    {
        $length = mb_strlen($password);

        if ($length < self::PASSWORD_MIN_LENGTH || \strlen($password) > self::PASSWORD_MAX_LENGTH) {
            $violations['password'] = \sprintf('Passwords must be at least %d characters long.', self::PASSWORD_MIN_LENGTH);

            return null;
        }

        return $this->hashers->getPasswordHasher(User::class)->hash($password);
    }

    private function usernameFrom(string $source): string
    {
        $base = str_contains($source, '@') ? substr($source, 0, (int) strpos($source, '@')) : $source;
        $username = substr(Slugs::fromTitle($base, 'en'), 0, self::USERNAME_MAX_LENGTH - 4);

        return 'untitled' === $username ? 'user' : rtrim($username, '-');
    }

    private function freeUsername(string $username): string
    {
        $candidate = $username;

        for ($number = 2; $this->usernameTaken($candidate); ++$number) {
            $candidate = $username.'-'.$number;
        }

        return $candidate;
    }

    private function usernameTaken(string $username, ?string $exceptId = null): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM quire.app_user WHERE username = ? AND id IS DISTINCT FROM ?::uuid', [$username, $exceptId]);
    }

    /**
     * Serialises choosing usernames until the transaction ends, so two new users can't take the same.
     */
    private function lockUsernames(): void
    {
        $this->connection->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('quire.app_user.username', 0))");
    }

    /**
     * @param list<string> $values Plain words, which need no quoting in an array literal
     */
    private function array(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
