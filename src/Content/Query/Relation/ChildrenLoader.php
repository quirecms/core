<?php

declare(strict_types=1);

namespace Quire\Content\Query\Relation;

use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\RelationLoaderInterface;

/**
 * `->with('children')`: each item's direct children, by position.
 *
 * @internal Registered by core's extend.php
 */
final readonly class ChildrenLoader implements RelationLoaderInterface
{
    public function load(array $items, ContentQuery $query): array
    {
        $values = [];

        foreach ($items as $item) {
            $values[$item->id] = [];
        }

        $children = $query->related()
            ->where('parent_id', 'in', array_keys($values))
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        foreach ($children as $child) {
            if (null !== $child->parentId) {
                $values[$child->parentId][] = $child;
            }
        }

        return $values;
    }
}
