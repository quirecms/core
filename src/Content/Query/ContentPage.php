<?php

declare(strict_types=1);

namespace Quire\Content\Query;

use Quire\Content\Content;

/**
 * One page of a content query's results, from {@see ContentQuery::paginate()}.
 *
 *     {% set posts = quire_content('post').paginate(app.request.query.getInt('page', 1)) %}
 *     {% for post in posts %}…{% endfor %}
 *     {% if posts.hasNextPage %}<a href="?page={{ posts.page + 1 }}">Older posts</a>{% endif %}
 *
 * @api
 *
 * @implements \IteratorAggregate<int, Content>
 */
final readonly class ContentPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Content> $items
     * @param int           $page  Starting at 1
     * @param int           $total How many items match the query, on all pages
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->pageCount();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * Whether the page is past the last one, e.g. a stale link: a listing should respond with 404.
     */
    public function isOutOfRange(): bool
    {
        return $this->page > $this->pageCount();
    }

    /**
     * The number of items on this page.
     */
    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * @return \ArrayIterator<int, Content>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
