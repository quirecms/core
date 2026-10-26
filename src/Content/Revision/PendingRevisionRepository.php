<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Doctrine\DBAL\Connection;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Internal\Row;

/**
 * Reads pending revisions and their reviews, and shows what publishing one would give. Change them with
 * {@see PendingRevisionWriter}; publish them with {@see \Quire\Content\ContentWriter::publishPendingRevision()}.
 *
 * @api
 */
final readonly class PendingRevisionRepository
{
    private const string COLUMNS = 'id, content_id, base_version, base, title, excerpt, body, body_format, fields, message, state, author_id, version, created_at, updated_at, publish_at, publish_problem, scheduled_by';

    public function __construct(
        private Connection $connection,
        private ContentRepository $content,
    ) {
    }

    public function find(string $id): ?PendingRevision
    {
        if (!Row::isUuid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content_pending_revision WHERE id = ?', self::COLUMNS), [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @throws ContentNotFoundException
     */
    public function get(string $id): PendingRevision
    {
        return $this->find($id) ?? throw ContentNotFoundException::forPendingRevision($id);
    }

    /**
     * @param string|null $authorId Only those of this author, e.g. to continue one's own changes
     *
     * @return list<PendingRevision> Most recently saved first
     */
    public function forContent(string $contentId, ?string $authorId = null): array
    {
        if (!Row::isUuid($contentId) || (null !== $authorId && !Row::isUuid($authorId))) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.content_pending_revision WHERE content_id = :content AND (CAST(:author AS uuid) IS NULL OR author_id = :author) ORDER BY updated_at DESC, id DESC', self::COLUMNS),
            ['content' => $contentId, 'author' => $authorId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Changes to be published in this time, the soonest first, e.g. for a calendar or a week's plan. Items
     * in the trash are left out.
     *
     * @return list<PendingRevision>
     */
    public function scheduledBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 200): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.content_pending_revision p WHERE publish_at >= ? AND publish_at < ? AND EXISTS (SELECT 1 FROM quire.content c WHERE c.id = p.content_id AND c.deleted_at IS NULL) ORDER BY publish_at, id LIMIT ?', self::COLUMNS),
            [$from->format(\DATE_ATOM), $to->format(\DATE_ATOM), max(1, $limit)],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Pending revisions waiting for a reviewer, of items not in the trash: e.g. for a reviewer's list of
     * work.
     *
     * @return list<PendingRevision> Longest waiting first
     */
    public function awaitingReview(int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.content_pending_revision p WHERE state = ? AND EXISTS (SELECT 1 FROM quire.content c WHERE c.id = p.content_id AND c.deleted_at IS NULL) ORDER BY updated_at, id LIMIT ?', self::COLUMNS),
            [ReviewState::InReview->value, max(1, $limit)],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * An author's pending revisions, of items not in the trash.
     *
     * @return list<PendingRevision> Most recently saved first
     */
    public function byAuthor(string $authorId, int $limit = 50): array
    {
        if (!Row::isUuid($authorId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.content_pending_revision p WHERE author_id = ? AND EXISTS (SELECT 1 FROM quire.content c WHERE c.id = p.content_id AND c.deleted_at IS NULL) ORDER BY updated_at DESC, id DESC LIMIT ?', self::COLUMNS),
            [$authorId, max(1, $limit)],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return list<Review> Oldest first
     */
    public function reviews(string $pendingRevisionId): array
    {
        if (!Row::isUuid($pendingRevisionId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, pending_revision_id, revision_version, verdict, reviewer_id, message, created_at FROM quire.content_revision_review WHERE pending_revision_id = ? ORDER BY id',
            [$pendingRevisionId],
        );

        return array_map(static function (array $values): Review {
            $row = new Row($values);

            return new Review(
                id: $row->string('id'),
                pendingRevisionId: $row->string('pending_revision_id'),
                revisionVersion: $row->int('revision_version'),
                verdict: Verdict::from($row->string('verdict')),
                reviewerId: $row->nullableString('reviewer_id'),
                message: $row->nullableString('message'),
                createdAt: $row->date('created_at'),
            );
        }, $rows);
    }

    /**
     * What publishing it now would give: the changes merged with what was published since it was started.
     */
    public function merge(PendingRevision $pending, ?Content $live = null): Merge
    {
        return Merge::of($pending->base, Snapshot::of($live ?? $this->content->get($pending->contentId)), $pending->proposed);
    }

    /**
     * The item as it would be with the changes published, to preview it in its templates. Where the changes
     * conflict with the live item, the preview shows the changes.
     */
    public function preview(PendingRevision $pending): Content
    {
        $live = $this->content->get($pending->contentId);
        $merged = $this->merge($pending, $live)->result;

        return $live->with([
            'title' => $merged->title,
            'excerpt' => $merged->excerpt,
            'body' => $merged->body,
            'bodyFormat' => $merged->bodyFormat,
            'fields' => $merged->fields,
            // Rendered HTML is cached by item and version; this keeps the preview apart.
            'attributes' => [...$live->attributes, 'pending_revision' => $pending],
        ]);
    }

    /**
     * Loads it and locks its row until the current transaction ends.
     *
     * @internal Used by the writers
     *
     * @throws ContentNotFoundException
     */
    public function lock(string $id): PendingRevision
    {
        if (!Row::isUuid($id)) {
            throw ContentNotFoundException::forPendingRevision($id);
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content_pending_revision WHERE id = ? FOR UPDATE', self::COLUMNS), [$id]);

        return false === $row ? throw ContentNotFoundException::forPendingRevision($id) : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function hydrate(array $values): PendingRevision
    {
        $row = new Row($values);

        return new PendingRevision(
            id: $row->string('id'),
            contentId: $row->string('content_id'),
            baseVersion: $row->int('base_version'),
            base: Snapshot::fromArray($row->jsonObject('base')),
            proposed: new Snapshot($row->string('title'), $row->nullableString('excerpt'), $row->string('body'), $row->string('body_format'), $row->jsonObject('fields')),
            message: $row->nullableString('message'),
            state: ReviewState::from($row->string('state')),
            authorId: $row->nullableString('author_id'),
            version: $row->int('version'),
            createdAt: $row->date('created_at'),
            updatedAt: $row->date('updated_at'),
            publishAt: $row->nullableDate('publish_at'),
            publishProblem: $row->nullableString('publish_problem'),
            scheduledBy: $row->nullableString('scheduled_by'),
        );
    }
}
