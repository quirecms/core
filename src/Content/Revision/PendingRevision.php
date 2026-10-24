<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * Changes to a content item that aren't live yet: a working copy of its text and fields, saved as the
 * editor types and published with {@see \Quire\Content\ContentWriter::publishPendingRevision()}.
 *
 * It remembers what the item looked like when it was started ({@see $base}), so changes others publish
 * in the meantime are kept: publishing merges, like a version control system does, and only edits to
 * the same lines or fields conflict. An item can have several pending revisions, e.g. one per editor.
 *
 * @api
 */
final readonly class PendingRevision
{
    /**
     * @param int                     $baseVersion    The item's version it was started from (or rebased onto)
     * @param Snapshot                $base           The item's text and fields at that version
     * @param Snapshot                $proposed       The text and fields to publish
     * @param string|null             $message        What the change is about, e.g. "Fix the opening hours"; becomes the revision's message
     * @param string|null             $authorId       Who writes it
     * @param int                     $version        Increases with each save, for optimistic locking
     * @param \DateTimeImmutable|null $publishAt      When the changes are published, by the task quire.publish-due
     * @param string|null             $publishProblem Why publishing them at their time failed, e.g. a conflict
     * @param string|null             $scheduledBy    Who set the time
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public int $baseVersion,
        public Snapshot $base,
        public Snapshot $proposed,
        public ?string $message,
        public ReviewState $state,
        public ?string $authorId,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $publishAt = null,
        public ?string $publishProblem = null,
        public ?string $scheduledBy = null,
    ) {
    }

    /**
     * What it changes, compared with where it started.
     */
    public function diff(): Diff
    {
        return Diff::between($this->base, $this->proposed);
    }
}
