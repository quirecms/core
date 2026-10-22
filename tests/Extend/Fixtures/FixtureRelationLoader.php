<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\RelationLoaderInterface;

final class FixtureRelationLoader implements RelationLoaderInterface
{
    public function load(array $items, ContentQuery $query): array
    {
        return [];
    }
}
