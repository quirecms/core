<?php

declare(strict_types=1);

namespace Quire\User\Token;

use Doctrine\DBAL\Connection;
use Quire\User\User;
use Quire\User\UserRepository;

/**
 * One-time tokens for links emailed to users, e.g. to reset a password or confirm an email.
 *
 * A token is a selector, which finds it, and a secret verifier, which is stored only as a SHA-256 hash: the
 * table alone gives nobody working links. A token works once, until it expires, and only while the user's
 * email is the one it was sent to. Issuing a new token for a purpose replaces the user's earlier ones.
 *
 * @internal
 */
final readonly class UserTokens
{
    public const string PASSWORD_RESET = 'password_reset';

    public const string EMAIL_VERIFICATION = 'email_verification';

    /** A new user, invited by an admin, chooses their password */
    public const string INVITATION = 'invitation';

    public function __construct(
        private Connection $connection,
        private UserRepository $users,
    ) {
    }

    /**
     * @return string The token for the link, e.g. "3f2a….Zk9x…"
     */
    public function issue(User $user, string $purpose, \DateInterval $lifetime): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->connection->transactional(function () use ($user, $purpose, $lifetime, $selector, $verifier): void {
            // The user's earlier tokens for the purpose, and anyone's expired ones, stop working.
            $this->connection->executeStatement('DELETE FROM quire.user_token WHERE (user_id = ? AND purpose = ?) OR expires_at <= now()', [$user->id, $purpose]);
            $this->connection->executeStatement(
                'INSERT INTO quire.user_token (selector, verifier_hash, user_id, purpose, email, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$selector, hash('sha256', $verifier), $user->id, $purpose, $user->email, new \DateTimeImmutable()->add($lifetime)->format(\DATE_ATOM)],
            );
        });

        return $selector.'.'.$verifier;
    }

    /**
     * Whether a token for the purpose was issued to the user since the given time, e.g. to send at most
     * one email every few minutes.
     */
    public function issuedSince(User $user, string $purpose, \DateTimeImmutable $since): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM quire.user_token WHERE user_id = ? AND purpose = ? AND created_at > ?',
            [$user->id, $purpose, $since->format(\DATE_ATOM)],
        );
    }

    /**
     * The user the token is for, if it's valid; it stays valid.
     */
    public function check(string $token, string $purpose): ?User
    {
        return $this->find($token, $purpose);
    }

    /**
     * The user the token is for, if it's valid, and every token of theirs for the purpose stops working.
     */
    public function consume(string $token, string $purpose): ?User
    {
        return $this->connection->transactional(function () use ($token, $purpose): ?User {
            $user = $this->find($token, $purpose, lock: true);

            if ($user instanceof User) {
                $this->connection->executeStatement('DELETE FROM quire.user_token WHERE user_id = ? AND purpose = ?', [$user->id, $purpose]);
            }

            return $user;
        });
    }

    private function find(string $token, string $purpose, bool $lock = false): ?User
    {
        $parts = explode('.', $token, 2);

        if (2 !== \count($parts) || 1 !== preg_match('/^[0-9a-f]{32}$/', $parts[0])) {
            return null;
        }

        [$selector, $verifier] = $parts;
        $row = $this->connection->fetchAssociative(
            'SELECT verifier_hash, user_id, email FROM quire.user_token WHERE selector = ? AND purpose = ? AND expires_at > now()'.($lock ? ' FOR UPDATE' : ''),
            [$selector, $purpose],
        );

        if (false === $row || !\is_string($row['verifier_hash']) || !hash_equals($row['verifier_hash'], hash('sha256', $verifier))) {
            return null;
        }

        $user = \is_string($row['user_id']) ? $this->users->find($row['user_id']) : null;
        $sentTo = \is_string($row['email']) ? mb_strtolower($row['email']) : null;

        // Sent to an email the user no longer has: whoever reads that inbox now isn't them.
        if (!$user instanceof User || !$user->isActive() || null === $user->email || mb_strtolower($user->email) !== $sentTo) {
            return null;
        }

        return $user;
    }
}
