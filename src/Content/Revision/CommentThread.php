<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * A comment and its replies, with where its text is in the current version of the changes.
 *
 * @api
 */
final readonly class CommentThread
{
    /**
     * @param list<Comment> $replies  Oldest first
     * @param int|null      $line     For the body: the line its text is on now; null without an anchor, or when outdated
     * @param bool          $outdated Whether the text it points at isn't in the changes any more
     */
    public function __construct(
        public Comment $comment,
        public array $replies,
        public ?int $line,
        public bool $outdated,
    ) {
    }
}
