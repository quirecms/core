<?php

declare(strict_types=1);

namespace Quire\Content\Twig;

use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\QueryRunner;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * @internal Loaded by Twig when a template calls a content function, so templates without one don't
 *           build the query services
 */
final readonly class ContentRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private QueryRunner $queries,
    ) {
    }

    /**
     * A lazy query: it runs when the template iterates it, with at most {@see ContentQuery::TEMPLATE_MAX_LIMIT} items.
     */
    public function query(string $type, string ...$types): ContentQuery
    {
        return $this->queries->query([$type, ...array_values($types)], template: true);
    }
}
