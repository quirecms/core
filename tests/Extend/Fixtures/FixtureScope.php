<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Quire\Content\Query\ContentQuery;

final class FixtureScope
{
    public function __invoke(ContentQuery $query, string $tag = 'php'): ContentQuery
    {
        return $query->where('fields.tags', 'contains', $tag);
    }
}
