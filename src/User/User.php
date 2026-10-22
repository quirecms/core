<?php

declare(strict_types=1);

namespace Quire\User;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user account. Read-only: change it through {@see UserWriter}.
 *
 * It's also the user Symfony's security works with, so `$this->getUser()` in a controller and
 * `app.user` in a template are a Quire user. Its identifier is the ID, so it stays the same when the
 * email or username changes.
 *
 * @api
 */
final readonly class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ACTIVE = 'active';

    public const string SUSPENDED = 'suspended';

    /**
     * @param string|null          $email        Null for users who only sign in through another provider
     * @param string|null          $passwordHash Null for users without a password
     * @param list<string>         $roles        Quire's role names, e.g. ["editor"]; see {@see Role}
     * @param string               $status       {@see ACTIVE} or {@see SUSPENDED}
     * @param array<string, mixed> $attributes   Values extensions keep about the user
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public string $username,
        public string $displayName,
        public ?string $passwordHash,
        public array $roles,
        public string $status,
        public array $attributes,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $lastLoginAt,
    ) {
    }

    public function isActive(): bool
    {
        return self::ACTIVE === $this->status;
    }

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->id);

        return $this->id;
    }

    /**
     * Symfony's role names: ROLE_USER for everyone, and ROLE_<NAME> for each of Quire's roles, e.g.
     * ROLE_EDITOR.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER', ...array_map(static fn (string $role): string => 'ROLE_'.strtoupper(str_replace('-', '_', $role)), $this->roles)];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * Keeps the password hash out of the session: only a checksum of it is stored, which is enough for
     * Symfony to sign the user out everywhere once the password changes.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        /** @var array<string, mixed> $data */
        $data = get_object_vars($this);
        $data['passwordHash'] = null === $this->passwordHash ? null : hash('crc32c', $this->passwordHash);

        return $data;
    }

    /**
     * @param array{id: string, email: ?string, username: string, displayName: string, passwordHash: ?string, roles: list<string>, status: string, attributes: array<string, mixed>, createdAt: \DateTimeImmutable, updatedAt: \DateTimeImmutable, lastLoginAt: ?\DateTimeImmutable} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->username = $data['username'];
        $this->displayName = $data['displayName'];
        $this->passwordHash = $data['passwordHash'];
        $this->roles = $data['roles'];
        $this->status = $data['status'];
        $this->attributes = $data['attributes'];
        $this->createdAt = $data['createdAt'];
        $this->updatedAt = $data['updatedAt'];
        $this->lastLoginAt = $data['lastLoginAt'];
    }
}
