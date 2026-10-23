<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * A reviewer's verdict on a pending revision, with an optional message.
 *
 * @api
 */
final readonly class Review
{
    /**
     * @param int         $revisionVersion The pending revision's version the reviewer saw
     * @param string|null $reviewerId      Null when the reviewer's account was deleted, or nobody was named
     */
    public function __construct(
        public string $id,
        public string $pendingRevisionId,
        public int $revisionVersion,
        public Verdict $verdict,
        public ?string $reviewerId,
        public ?string $message,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
