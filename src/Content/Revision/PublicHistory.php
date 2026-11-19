<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Doctrine\DBAL\Connection;
use Quire\Content\Content;

/**
 * A published item's public history, newest first: the changes its authors told readers about when
 * they published them (`quire_history(item)`). Only for an item visitors can see, and only what was
 * published: drafts, pending changes and reviews never reach it, and the editors' revision messages
 * aren't part of it. Its first entry, "Published", is the item's own publication date, which a theme
 * adds; a history can start before the first change if an editor gave an earlier date.
 *
 * @api
 */
final readonly class PublicHistory
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<PublicChange>
     */
    public function of(Content $item): array
    {
        if (!$item->isPublic()) {
            return [];
        }

        /** @var list<array{kind: string, note: string, published_at: string, content_version: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT kind, note, published_at, content_version FROM quire.content_public_change WHERE content_id = ? ORDER BY published_at DESC, content_version DESC',
            [$item->id],
        );

        return array_map(static fn (array $row): PublicChange => new PublicChange(
            PublicChangeKind::from($row['kind']),
            $row['note'],
            new \DateTimeImmutable($row['published_at']),
            (int) $row['content_version'],
        ), $rows);
    }
}
