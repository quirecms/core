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
    private const string COLUMNS = 'id, email, username, display_name, password_hash, roles, status, attributes, created_at, updated_at, last_login_at, email_verified_at';

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
     * The identities at other providers linked to the user, e.g. a Google account, or the host
     * application's user.
     *
     * @return list<array{provider: string, subject: string, claims: array<string, mixed>, created_at: \DateTimeImmutable, last_used_at: ?\DateTimeImmutable}>
     */
    public function identities(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT provider, subject, claims, created_at, last_used_at FROM quire.user_identity WHERE user_id = ? ORDER BY provider, created_at', [$userId]);

        return array_map(static function (array $values): array {
            $row = new Row($values);
            $claims = $row->jsonObject('claims');

            return [
                'provider' => $row->string('provider'),
                'subject' => $row->string('subject'),
                'claims' => array_filter($claims, \is_string(...), \ARRAY_FILTER_USE_KEY),
                'created_at' => $row->date('created_at'),
                'last_used_at' => $row->nullableDate('last_used_at'),
            ];
        }, $rows);
    }

    /**
     * What the provider said about a linked identity when it was last used.
     *
     * @return array<string, mixed>|null Null when no user has the identity
     */
    public function identityClaims(string $provider, string $subject): ?array
    {
        $claims = $this->connection->fetchOne('SELECT claims FROM quire.user_identity WHERE provider = ? AND subject = ?', [$provider, $subject]);

        if (!\is_string($claims)) {
            return null;
        }

        $decoded = json_decode($claims, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? array_filter($decoded, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
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

    /**
     * Users matching a search, a page at a time, e.g. for an admin's list of users.
     *
     * @param string|null $text    Part of the name, username or email
     * @param string|null $role    Only users with this role
     * @param string|null $status  Only users with this status, e.g. {@see User::SUSPENDED}
     * @param string      $sort    "name", "username", "created" or "login" (last sign-in, never last)
     * @param bool|null   $invited Only users invited who haven't chosen their password yet (true), or the others (false)
     *
     * @return array{users: list<User>, total: int} The page, and how many users match in all
     */
    public function search(?string $text = null, ?string $role = null, ?string $status = null, string $sort = 'name', bool $descending = false, int $limit = 50, int $offset = 0, ?bool $invited = null): array
    {
        [$conditions, $parameters] = [[], []];
        $text = trim((string) $text);

        if ('' !== $text) {
            $conditions[] = '(display_name ILIKE ? OR username ILIKE ? OR email ILIKE ?)';
            $pattern = '%'.addcslashes($text, '%_\\').'%';
            $parameters[] = $pattern;
            $parameters[] = $pattern;
            $parameters[] = $pattern;
        }

        if (null !== $role) {
            $conditions[] = '? = ANY(roles)';
            $parameters[] = $role;
        }

        if (null !== $status) {
            $conditions[] = 'status = ?';
            $parameters[] = $status;
        }

        if (null !== $invited) {
            $conditions[] = ($invited ? '' : 'NOT ')."EXISTS (SELECT 1 FROM quire.user_token t WHERE t.user_id = app_user.id AND t.purpose = 'invitation')";
        }

        $order = match ($sort) {
            'username' => 'username',
            'created' => 'created_at',
            'login' => 'last_login_at',
            default => 'lower(display_name)',
        };
        $direction = $descending ? 'DESC' : 'ASC';

        // The total comes with the page, in one query.
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s, count(*) OVER () AS total FROM quire.app_user %s ORDER BY %s %s NULLS LAST, id LIMIT %d OFFSET %d', self::COLUMNS, [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions), $order, $direction, max(0, $limit), max(0, $offset)),
            $parameters,
        );
        $total = $rows[0]['total'] ?? null;

        // Past the last page, the total needs its own query.
        if ([] === $rows && $offset > 0) {
            $total = $this->connection->fetchOne(\sprintf('SELECT count(*) FROM quire.app_user %s', [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions)), $parameters);
        }

        return ['users' => array_map($this->hydrate(...), $rows), 'total' => is_numeric($total) ? (int) $total : 0];
    }

    /**
     * How many users have each role, e.g. to show what changing a role affects.
     *
     * @return array<string, int> Role => users, active and suspended
     */
    public function countByRole(): array
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->connection->fetchAllKeyValue('SELECT role, count(*) FROM quire.app_user, unnest(roles) AS role GROUP BY role');

        return array_map(static fn (int|string $count): int => (int) $count, $counts);
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
            emailVerifiedAt: $row->nullableDate('email_verified_at'),
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
