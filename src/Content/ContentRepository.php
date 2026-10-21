<?php

declare(strict_types=1);

namespace Quire\Content;

use Doctrine\DBAL\Connection;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Internal\Row;

/**
 * Loads content items by ID, including those in the trash.
 *
 * @api
 */
final readonly class ContentRepository
{
    private const string COLUMNS = 'id, type, status, locale, translation_group_id, title, slug, parent_id, position, excerpt, body, body_format, fields, author_id, version, published_at, created_at, updated_at, deleted_at';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(string $id): ?Content
    {
        if (!Row::isUuid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM content WHERE id = ?', self::COLUMNS), [$id]);

        return false === $row ? null : self::hydrate($row);
    }

    /**
     * @throws ContentNotFoundException
     */
    public function get(string $id): Content
    {
        return $this->find($id) ?? throw ContentNotFoundException::forId($id);
    }

    /**
     * Loads the item and locks its row until the current transaction ends.
     *
     * @internal Used by {@see ContentWriter}
     *
     * @throws ContentNotFoundException
     */
    public function lock(string $id): Content
    {
        if (!Row::isUuid($id)) {
            throw ContentNotFoundException::forId($id);
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM content WHERE id = ? FOR UPDATE', self::COLUMNS), [$id]);

        return false === $row ? throw ContentNotFoundException::forId($id) : self::hydrate($row);
    }

    /**
     * The most recently created items, for the command line.
     *
     * @internal Sites and extensions will query content with the content query builder
     *
     * @return list<Content>
     */
    public function latest(?string $type = null, ?string $status = null, bool $trashed = false, int $limit = 50): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(self::COLUMNS)
            ->from('content')
            ->where($trashed ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $type) {
            $query->andWhere('type = :type')->setParameter('type', $type);
        }

        if (null !== $status) {
            $query->andWhere('status = :status')->setParameter('status', $status);
        }

        return array_values(array_map(self::hydrate(...), $query->fetchAllAssociative()));
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Content
    {
        $row = new Row($row);

        return new Content(
            id: $row->string('id'),
            type: $row->string('type'),
            status: $row->string('status'),
            locale: $row->string('locale'),
            translationGroupId: $row->nullableString('translation_group_id'),
            title: $row->string('title'),
            slug: $row->string('slug'),
            parentId: $row->nullableString('parent_id'),
            position: $row->int('position'),
            excerpt: $row->nullableString('excerpt'),
            body: $row->string('body'),
            bodyFormat: $row->string('body_format'),
            fields: $row->jsonObject('fields'),
            authorId: $row->nullableString('author_id'),
            version: $row->int('version'),
            publishedAt: $row->nullableDate('published_at'),
            createdAt: $row->date('created_at'),
            updatedAt: $row->date('updated_at'),
            deletedAt: $row->nullableDate('deleted_at'),
        );
    }
}
