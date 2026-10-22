<?php

declare(strict_types=1);

namespace Quire\Content\Query;

use Quire\Content\Content;

/**
 * Loads a relation, such as an item's comments, for many items at once.
 *
 * Register it with {@see \Quire\Extend\Query::relation()}. A query that asks for the relation with
 * `->with('comments')` calls the loader once per page of results, never once per item, so listings make the
 * same number of database queries however many items they show.
 *
 * @api
 */
interface RelationLoaderInterface
{
    /**
     * @param non-empty-list<Content> $items
     * @param ContentQuery            $query The query that returned the items. Use {@see ContentQuery::related()} to
     *                                       query related content with the same visibility rules.
     *
     * @return array<string, mixed> The relation's value for each item, by item ID. Items left out get null.
     */
    public function load(array $items, ContentQuery $query): array;
}
