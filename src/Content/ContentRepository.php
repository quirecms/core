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
    public const string COLUMNS = 'id, type, status, locale, translation_group_id, title, slug, path, parent_id, position, excerpt, body, body_format, fields, author_id, version, published_at, created_at, updated_at, deleted_at';

    /** The methods of ContentQuery that {@see toArray()} writes down */
    private const array REPLAYED = ['where', 'whereSql', 'withIssues', 'published', 'search', 'scope', 'withoutVisibilityScope', 'withTrashed', 'onlyTrashed', 'select', 'join', 'with', 'orderBy', 'limit', 'offset'];

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

    /**
     * Makes a query again from what {@see ContentQuery::toArray()} wrote down, e.g. in a background job
     * that acts on every item a list shows.
     *
     * Only for what Quire's or an extension's code wrote down: never from what a visitor sent, as it may
     * hold SQL.
     *
     * @param array{types: non-empty-list<string>, template?: bool, calls?: list<array{0: string, 1: list<mixed>}>} $query
     *
     * @throws Query\Exception\InvalidQueryException
     * @throws Type\Exception\UnknownContentTypeException
     */
    public function queryFromArray(array $query): ContentQuery
    {
        $made = $this->queries->query($query['types'], $query['template'] ?? false);

        foreach ($query['calls'] ?? [] as [$method, $arguments]) {
            if (!\in_array($method, self::REPLAYED, true)) {
                throw new Query\Exception\InvalidQueryException(\sprintf('A query can\'t be made with "%s()".', $method));
            }

            $made = [$made, $method](...array_map(ContentQuery::decode(...), $arguments));
        }

        return $made;
    }

    public function find(string $id): ?Content
    {
        if (!Row::isUuid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content WHERE id = ?', self::COLUMNS), [$id]);

        return false === $row ? null : self::hydrate($row);
    }

    /**
     * The items with these IDs, in one query, e.g. for a list that names them. IDs of no item are left out.
     *
     * @param iterable<string> $ids
     *
     * @return array<string, Content> By ID
     */
    public function findMany(iterable $ids): array
    {
        $ids = array_values(array_unique(array_filter([...$ids], Row::isUuid(...))));

        if ([] === $ids) {
            return [];
        }

        $items = [];

        foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT %s FROM quire.content WHERE id = ANY(CAST(? AS uuid[]))', self::COLUMNS), ['{'.implode(',', $ids).'}']) as $row) {
            $item = self::hydrate($row);
            $items[$item->id] = $item;
        }

        return $items;
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

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content WHERE id = ? FOR UPDATE', self::COLUMNS), [$id]);

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
            path: $row->string('path'),
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
