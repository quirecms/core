<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * A comment on changes that aren't published yet: a thread's first comment, optionally pointing at
 * text, or a reply.
 *
 * @api
 */
final readonly class Comment
{
    /**
     * @param string|null $parentId   The thread's first comment, for a reply
     * @param string|null $authorId   Null when the author's account was deleted
     * @param string|null $resolvedBy For a thread: who marked it resolved
     */
    public function __construct(
        public string $id,
        public string $pendingRevisionId,
        public ?string $parentId,
        public ?string $authorId,
        public string $body,
        public ?CommentAnchor $anchor,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $resolvedAt = null,
        public ?string $resolvedBy = null,
    ) {
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt instanceof \DateTimeImmutable;
    }
}
