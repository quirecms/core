<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * An invitation not used yet: when it was sent, and until when its link works.
 *
 * @api
 */
final readonly class Invitation
{
    public function __construct(
        public \DateTimeImmutable $sentAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
