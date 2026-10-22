<?php

declare(strict_types=1);

namespace Quire\Content\Query\Relation;

use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\RelationLoaderInterface;

/**
 * `->with('parent')`: each item's parent, or null for top-level items and parents visitors can't see.
 *
 * @internal Registered by core's extend.php
 */
final readonly class ParentLoader implements RelationLoaderInterface
{
    public function load(array $items, ContentQuery $query): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Content $item): ?string => $item->parentId, $items), static fn (?string $id): bool => null !== $id)));

        if ([] === $ids) {
            return [];
        }

        $parents = [];

        foreach ($query->related()->where('id', 'in', $ids)->get() as $parent) {
            $parents[$parent->id] = $parent;
        }

        $values = [];

        foreach ($items as $item) {
            $values[$item->id] = null === $item->parentId ? null : $parents[$item->parentId] ?? null;
        }

        return $values;
    }
}
