<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;

/**
 * Reads the revisions of content items. Restore one with {@see \Quire\Content\ContentWriter::restoreRevision()}.
 *
 * @api
 */
final readonly class RevisionRepository
{
    private const string COLUMNS = 'id, content_id, content_version, title, excerpt, body, body_format, fields, author_id, message, created_at, approved_by';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<Revision> Newest first
     */
    public function forContent(string $contentId, int $limit = 100): array
    {
        if (!Row::isUuid($contentId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT %s FROM quire.content_revision WHERE content_id = ? ORDER BY content_version DESC LIMIT ?', self::COLUMNS),
            [$contentId, $limit],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * The item's text and fields as they were at a version of it: its revision of that version, or the
     * latest before it (a change of status raises the version, not the text). Null when that revision
     * is gone (pruned, see "quire.content.revisions_keep") or the item's type keeps none.
     */
    public function atVersion(string $contentId, int $version): ?Revision
    {
        if (!Row::isUuid($contentId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT %s FROM quire.content_revision WHERE content_id = ? AND content_version <= ? ORDER BY content_version DESC LIMIT 1', self::COLUMNS),
            [$contentId, $version],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function find(string $id): ?Revision
    {
        if (!Row::isUuid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content_revision WHERE id = ?', self::COLUMNS), [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Revision
    {
        $row = new Row($row);

        return new Revision(
            id: $row->string('id'),
            contentId: $row->string('content_id'),
            contentVersion: $row->int('content_version'),
            title: $row->string('title'),
            excerpt: $row->nullableString('excerpt'),
            body: $row->string('body'),
            bodyFormat: $row->string('body_format'),
            fields: $row->jsonObject('fields'),
            authorId: $row->nullableString('author_id'),
            message: $row->nullableString('message'),
            createdAt: $row->date('created_at'),
            approvedBy: $row->nullableString('approved_by'),
        );
    }
}
