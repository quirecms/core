<?php

declare(strict_types=1);

namespace Quire\User;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;

/**
 * Finds users.
 *
 * @api
 */
final readonly class UserRepository
{
    private const string COLUMNS = 'id, email, username, display_name, password_hash, roles, status, attributes, created_at, updated_at, last_login_at';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(string $id): ?User
    {
        return Row::isUuid($id) ? $this->one('id = ?', [$id]) : null;
    }

    /**
     * Emails are compared regardless of case.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->one('lower(email) = lower(?)', [trim($email)]);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->one('username = ?', [strtolower(trim($username))]);
    }

    /**
     * What people type to sign in: an email or a username.
     */
    public function findByLogin(string $login): ?User
    {
        return str_contains($login, '@') ? $this->findByEmail($login) : $this->findByUsername($login);
    }

    /**
     * An ID, an email or a username, e.g. as given on the command line.
     */
    public function findByReference(string $reference): ?User
    {
        return $this->find($reference) ?? $this->findByLogin($reference);
    }

    /**
     * The user an identity at another provider belongs to, e.g. ("google", "1234567890").
     */
    public function findByIdentity(string $provider, string $subject): ?User
    {
        return $this->one('id = (SELECT user_id FROM quire.user_identity WHERE provider = ? AND subject = ?)', [$provider, $subject]);
    }

    /**
     * @param string|null $role Only users with this role
     *
     * @return list<User> Oldest first
     */
    public function all(?string $role = null, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.app_user %s ORDER BY created_at, id LIMIT %d OFFSET %d', self::COLUMNS, null === $role ? '' : 'WHERE ? = ANY(roles)', max(0, $limit), max(0, $offset)),
            null === $role ? [] : [$role],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function count(?string $role = null): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM quire.app_user'.(null === $role ? '' : ' WHERE ? = ANY(roles)'), null === $role ? [] : [$role]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param list<mixed> $parameters
     */
    private function one(string $condition, array $parameters): ?User
    {
        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.app_user WHERE %s', self::COLUMNS, $condition), $parameters);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function hydrate(array $values): User
    {
        $row = new Row($values);

        return new User(
            id: $row->string('id'),
            email: $row->nullableString('email'),
            username: $row->string('username'),
            displayName: $row->string('display_name'),
            passwordHash: $row->nullableString('password_hash'),
            roles: $this->parseArray($row->string('roles')),
            status: $row->string('status'),
            attributes: $row->jsonObject('attributes'),
            createdAt: $row->date('created_at'),
            updatedAt: $row->date('updated_at'),
            lastLoginAt: $row->nullableDate('last_login_at'),
        );
    }

    /**
     * Reads a PostgreSQL array of role names, e.g. "{editor,admin}". Role names are plain words, so
     * they're never quoted.
     *
     * @return list<string>
     */
    private function parseArray(string $value): array
    {
        $inner = trim($value, '{}');

        return '' === $inner ? [] : explode(',', $inner);
    }
}
