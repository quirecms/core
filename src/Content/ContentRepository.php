<?php

declare(strict_types=1);

namespace Quire\Content;

use Doctrine\DBAL\Connection;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Internal\Row;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\QueryRunner;

/**
 * Loads content items: one by ID (including those in the trash), or many with a {@see ContentQuery}.
 *
 * @api
 */
final readonly class ContentRepository
{
    /** @internal */
    public const string COLUMNS = 'id, type, status, locale, translation_group_id, title, slug, parent_id, position, excerpt, body, body_format, fields, author_id, version, published_at, created_at, updated_at, deleted_at';

    public function __construct(
        private Connection $connection,
        private QueryRunner $queries,
    ) {
    }

    /**
     * Starts a query for items of one or more types, e.g. `$content->query('post')->latest()->limit(5)->get()`.
     *
     * Only items visitors may see are returned, unless the query opts out: see {@see ContentQuery}.
     *
     * @throws Type\Exception\UnknownContentTypeException
     */
    public function query(string $type, string ...$types): ContentQuery
    {
        return $this->queries->query([$type, ...array_values($types)]);
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
