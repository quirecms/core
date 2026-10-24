<?php

declare(strict_types=1);

namespace Quire\Content\Query;

use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Query\Internal\QueryRunner;
use Quire\Content\Query\Internal\SearchSql;
use Quire\Content\Status;

/**
 * A query for content items. Every method returns a new query, so a query can be shared and refined.
 *
 *     $posts = $content->query('post')
 *         ->scope('featured')
 *         ->where('fields.rating', '>=', 4)
 *         ->orderBy('fields.rating', 'desc')
 *         ->with('translations')
 *         ->paginate(page: 2, perPage: 10);
 *
 * In Twig: `{% for post in quire_content('post').latest.limit(5) %}`.
 *
 * Queries only return what visitors may see: published items (and scheduled ones whose date has passed),
 * not in the trash, and not hidden by an extension's visibility rule. Opt out explicitly with
 * {@see withoutVisibilityScope()} and {@see withTrashed()}.
 *
 * Create queries with {@see ContentRepository::query()}.
 *
 * @api
 *
 * @implements \IteratorAggregate<int, Content>
 *
 * @phpstan-type Condition array{sql: string, params: list<mixed>}
 */
final class ContentQuery implements \IteratorAggregate
{
    /** How many items a template query returns when it doesn't set a limit. */
    public const int TEMPLATE_DEFAULT_LIMIT = 10;

    /** The most items a template query can return at once. Paginate for more. */
    public const int TEMPLATE_MAX_LIMIT = 100;

    private const string ALIAS_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    private const string JOIN_PATTERN = '/^\s*(?:(?:LEFT|INNER|CROSS)\s+(?:OUTER\s+)?)?JOIN\s/i';

    /** @var list<Condition> */
    private array $conditions = [];

    /** @var array<string, Condition> */
    private array $selects = [];

    /** @var list<Condition> */
    private array $joins = [];

    /** @var list<array{sql: string, direction: 'ASC'|'DESC'}> */
    private array $orders = [];

    /** @var list<string> */
    private array $relations = [];

    private ?int $limit = null;

    private int $offset = 0;

    private bool $visibility = true;

    private bool $publishedOnly = false;

    /** @var 'exclude'|'include'|'only' */
    private string $trashed = 'exclude';

    /** @var array{query: string, terms: string}|null Set by {@see search()} */
    private ?array $search = null;

    private bool $snippets = false;

    /** @var list<Content>|null Fetched when the query is iterated */
    private ?array $results = null;

    /**
     * @internal Create queries with {@see ContentRepository::query()}
     *
     * @param non-empty-list<string> $types
     * @param bool                   $template Written in a template: limits apply, and raw SQL and opting out of
     *                                         visibility aren't allowed
     */
    public function __construct(
        private readonly QueryRunner $runner,
        private readonly array $types,
        private bool $template = false,
    ) {
        if ($template) {
            $this->limit = self::TEMPLATE_DEFAULT_LIMIT;
        }
    }

    public function __clone()
    {
        $this->results = null;
    }

    /**
     * Adds a filter on a column or a filterable field.
     *
     *     ->where('fields.featured', true)
     *     ->where('fields.rating', '>=', 4)
     *     ->where('fields.color', 'in', ['red', 'blue'])
     *     ->where('fields.tags', 'contains', 'php')
     *     ->where('published_at', '<', new \DateTimeImmutable('-1 year'))
     *
     * Operators: =, !=, <, <=, >, >=, in, not in, and for list fields contains (all of the values) and
     * contains any. Comparing with null finds items without a value.
     *
     * Columns: id, slug, locale, status, parent_id, author_id, translation_group_id, published_at,
     * created_at, updated_at. Fields must be declared with `filterable: true` (or `sortable: true`), so the
     * filter uses their index.
     *
     * @throws InvalidQueryException
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        [$operator, $value] = 2 === \func_num_args() ? ['=', $operator] : [$operator, $value];

        if (!\is_string($operator)) {
            throw new InvalidQueryException(\sprintf('The operator for "%s" must be a string such as ">=".', $field));
        }

        return $this->withCondition($this->runner->fields()->condition($this->types, $field, $operator, $value));
    }

    /**
     * Adds a condition in SQL. The content table is aliased "c"; pass values as `?` placeholders.
     *
     *     ->whereSql('EXISTS (SELECT 1 FROM review r WHERE r.content_id = c.id AND r.approved)')
     *
     * Not available in templates: wrap SQL in a scope (see {@see \Quire\Extend\Query::scope()}).
     *
     * @param list<mixed> $params
     */
    public function whereSql(string $sql, array $params = []): self
    {
        $this->assertNotTemplate('whereSql()');

        return $this->withCondition(['sql' => '('.$sql.')', 'params' => $params]);
    }

    /**
     * Only items visitors can see, including extensions' visibility rules, even when the visibility scope is
     * off (or, once there are permissions, when the viewer may see more).
     */
    public function published(): self
    {
        $query = clone $this;
        $query->publishedOnly = true;

        return $query;
    }

    /**
     * Items in any of these statuses. The visibility scope still applies, so this narrows what visitors see.
     */
    public function status(string ...$statuses): self
    {
        return $this->where('status', 'in', array_values($statuses));
    }

    public function locale(string $locale): self
    {
        return $this->where('locale', $locale);
    }

    /**
     * Direct children of an item in a hierarchical type, or the top-level items when null.
     */
    public function childrenOf(Content|string|null $parent): self
    {
        return $this->where('parent_id', $parent instanceof Content ? $parent->id : $parent);
    }

    /**
     * Only items that match the search terms, best matches first unless the query sets an order.
     *
     *     ->search('markdown editor')                  // both words
     *     ->search('"site search" -plugin')           // a phrase, without "plugin"; "or" between words
     *     ->search('mark', prefix: true)               // words starting with "mark", e.g. as you type
     *     ->search('markdown', snippets: true)         // with passages that show the terms
     *
     * Terms are matched in each item's language, so "running" finds "run" in English items. Titles weigh
     * most, then excerpts, then bodies. Each item gets the attribute "search_rank" (0 to 1), which the query
     * can also sort by; with snippets, "search_snippet" too: HTML with the terms in <mark>, from the
     * excerpt and the body, without Markdown's markup. Snippets take one query more, for the items returned.
     *
     * Like every condition, search leaves out what the viewer may not see. Blank terms change nothing.
     *
     * @param bool $prefix The last word matches words that start with it, and the terms are words only (no
     *                     phrases, "or" or "-")
     */
    public function search(string $terms, bool $prefix = false, bool $snippets = false): self
    {
        $parsed = SearchSql::parse($terms, $prefix);

        if (null === $parsed) {
            return clone $this;
        }

        $query = $this->withCondition(SearchSql::condition($parsed));
        $query->search = $parsed;
        $query->snippets = $snippets;
        $query->selects['search_rank'] = SearchSql::rank($parsed);

        return $query;
    }

    /**
     * Applies a named scope that an extension registered, e.g. `->scope('featured')` or `->scope('tagged', 'php')`.
     *
     * @throws InvalidQueryException
     */
    public function scope(string $name, mixed ...$arguments): self
    {
        // A scope is code an extension wrote, so it may use what templates can't, e.g. raw SQL.
        $trusted = clone $this;
        $trusted->template = false;

        $query = clone $this->runner->scope($trusted, $name, array_values($arguments));
        $query->template = $this->template;

        return $query;
    }

    /**
     * Turns off the visibility scope: drafts, archived items, scheduled items that aren't due, and items hidden
     * by extensions are included. Items in the trash still aren't; see {@see withTrashed()}. Trashed items are
     * never visible, so the trash options only matter without the visibility scope.
     *
     * For admin interfaces, the command line and other code that shows content to editors, never to visitors.
     */
    public function withoutVisibilityScope(): self
    {
        $this->assertNotTemplate('withoutVisibilityScope()');
        $query = clone $this;
        $query->visibility = false;

        return $query;
    }

    public function withTrashed(): self
    {
        $this->assertNotTemplate('withTrashed()');
        $query = clone $this;
        $query->trashed = 'include';

        return $query;
    }

    public function onlyTrashed(): self
    {
        $this->assertNotTemplate('onlyTrashed()');
        $query = clone $this;
        $query->trashed = 'only';

        return $query;
    }

    /**
     * Adds a value to each item, computed in SQL, e.g. a count from an extension's table. Read it with
     * `$post->attribute('comment_count')`, or `{{ post.comment_count }}` in Twig. Queries can sort by it.
     *
     *     ->select('comment_count', 'SELECT count(*) FROM comment cm WHERE cm.content_id = c.id')
     *
     * Not available in templates: wrap it in a scope.
     *
     * @param list<mixed> $params
     *
     * @throws InvalidQueryException
     */
    public function select(string $name, string $sql, array $params = []): self
    {
        $this->assertNotTemplate('select()');

        if (1 !== preg_match(self::ALIAS_PATTERN, $name)) {
            throw new InvalidQueryException(\sprintf('"%s" isn\'t a valid name for a selected value: use lowercase letters, digits and underscores, starting with a letter.', $name));
        }

        if (property_exists(Content::class, $name) || $this->runner->hasRelation($name)) {
            throw new InvalidQueryException(\sprintf('A selected value can\'t be named "%s": that\'s a %s of content items.', $name, property_exists(Content::class, $name) ? 'property' : 'relation'));
        }

        $query = clone $this;
        $query->selects[$name] = ['sql' => $sql, 'params' => $params];

        return $query;
    }

    /**
     * Joins another table, e.g. one with a row of aggregates per item. The join must not produce more than one
     * row per item; for lists of related rows, register a relation instead (see {@see \Quire\Extend\Query::relation()}).
     *
     *     ->join('LEFT JOIN review_stats rs ON rs.content_id = c.id')
     *     ->select('rating_avg', 'rs.average')
     *
     * Not available in templates: wrap it in a scope.
     *
     * @param list<mixed> $params
     *
     * @throws InvalidQueryException
     */
    public function join(string $sql, array $params = []): self
    {
        $this->assertNotTemplate('join()');

        if (1 !== preg_match(self::JOIN_PATTERN, $sql)) {
            throw new InvalidQueryException('A join starts with JOIN, LEFT JOIN, INNER JOIN or CROSS JOIN.');
        }

        $query = clone $this;
        $query->joins[] = ['sql' => $sql, 'params' => $params];

        return $query;
    }

    /**
     * Loads relations for all returned items at once, e.g. `->with('children')`, then read
     * `$page->attribute('children')`, or `{{ page.children }}` in Twig.
     * Core provides parent, children and translations; extensions register more.
     *
     * @throws InvalidQueryException
     */
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $this->runner->assertRelation($this->types, $relation);
        }

        $query = clone $this;
        $query->relations = array_values(array_unique([...$this->relations, ...array_values($relations)]));

        return $query;
    }

    /**
     * Sorts by a column (published_at, created_at, updated_at, title, position), a field declared with
     * `sortable: true` as "fields.<name>", or a selected value. Items without a value come last.
     *
     * Without an order, a query of one type uses the type's default order (see
     * {@see \Quire\Extend\ContentType::sortBy()}); a query of several types sorts by published_at, newest first.
     *
     * @throws InvalidQueryException
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);

        if ('ASC' !== $direction && 'DESC' !== $direction) {
            throw new InvalidQueryException(\sprintf('The sort direction is "asc" or "desc", not "%s".', strtolower($direction)));
        }

        $query = clone $this;
        $query->orders[] = ['sql' => $this->runner->fields()->sortExpression($this->types, $field, array_keys($this->selects)), 'direction' => $direction];

        return $query;
    }

    /**
     * Newest first, by publication date.
     */
    public function latest(): self
    {
        return $this->orderBy('published_at', 'desc');
    }

    public function oldest(): self
    {
        return $this->orderBy('published_at', 'asc');
    }

    /**
     * @param int|null $limit The most items to return; null for all (not in templates)
     *
     * @throws InvalidQueryException
     */
    public function limit(?int $limit): self
    {
        if (null !== $limit && $limit < 0) {
            throw new InvalidQueryException('The limit can\'t be negative.');
        }

        if ($this->template && (null === $limit || $limit > self::TEMPLATE_MAX_LIMIT)) {
            throw new InvalidQueryException(\sprintf('Templates can show at most %d items at once; use paginate() for more.', self::TEMPLATE_MAX_LIMIT));
        }

        $query = clone $this;
        $query->limit = $limit;

        return $query;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException('The offset can\'t be negative.');
        }

        $query = clone $this;
        $query->offset = $offset;

        return $query;
    }

    /**
     * Runs the query.
     *
     * @return list<Content>
     */
    public function get(): array
    {
        return $this->runner->fetch($this);
    }

    public function first(): ?Content
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * How many items match, regardless of limit and offset.
     */
    public function count(): int
    {
        return $this->runner->count($this);
    }

    /**
     * One page of items, with the total number of matching items.
     *
     * @param int      $page    Starting at 1. Lower numbers are treated as 1, so a page number from a URL can be
     *                          passed as is.
     * @param int|null $perPage Defaults to the query's limit, or 10
     *
     * @throws InvalidQueryException
     */
    public function paginate(int $page = 1, ?int $perPage = null): ContentPage
    {
        $perPage ??= $this->limit ?? self::TEMPLATE_DEFAULT_LIMIT;
        $page = max(1, $page);

        if ($perPage < 1 || ($this->template && $perPage > self::TEMPLATE_MAX_LIMIT)) {
            throw new InvalidQueryException(\sprintf('Pages have from 1 to %s items.', $this->template ? self::TEMPLATE_MAX_LIMIT : 'any number of'));
        }

        if ($page > intdiv(\PHP_INT_MAX, $perPage)) {
            throw new InvalidQueryException('The page number is too large.');
        }

        return $this->runner->paginate($this, $page, $perPage);
    }

    /**
     * Loads this query's relations ({@see with()}) into items fetched elsewhere, e.g. a single item that a
     * controller loaded by its slug.
     *
     * @param list<Content> $items
     *
     * @return list<Content>
     */
    public function load(array $items): array
    {
        return $this->runner->loadRelations($this, $items);
    }

    /**
     * A new query, with the same visibility rules, for items related to this query's: for relation loaders.
     */
    public function related(string ...$types): self
    {
        $query = $this->runner->query([] === $types ? $this->types : array_values($types));
        $query->visibility = $this->visibility;
        $query->publishedOnly = $this->publishedOnly;

        return $query;
    }

    /**
     * @return \ArrayIterator<int, Content>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results ??= $this->get());
    }

    /**
     * @return non-empty-list<string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * Whether only what visitors may see is returned. Relation loaders should apply the same rule to related items.
     */
    public function appliesVisibility(): bool
    {
        return $this->visibility || $this->publishedOnly;
    }

    /**
     * @internal
     *
     * @return array{query: string, terms: string}|null The search, when it should come with snippets
     */
    public function snippetSearch(): ?array
    {
        return $this->snippets ? $this->search : null;
    }

    /**
     * @internal
     *
     * @return list<string>
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * @internal
     *
     * @return array{conditions: list<Condition>, rest: bool} The query's conditions, and whether it has
     *                                                        anything else (joins, selects, order, …)
     */
    public function conditions(): array
    {
        return [
            'conditions' => $this->conditions,
            'rest' => [] !== $this->selects || [] !== $this->joins || [] !== $this->orders || [] !== $this->relations || null !== $this->limit || 0 !== $this->offset,
        ];
    }

    /**
     * @internal
     *
     * @param 'rows'|'count'|'page' $mode Page mode adds the total count as "_total"
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(string $mode = 'rows'): array
    {
        $params = [];
        $columns = [$this->runner->columns()];

        foreach ($this->selects as $name => $select) {
            $columns[] = \sprintf('(%s) AS "%s"', $select['sql'], $name);
            $params = [...$params, ...$select['params']];
        }

        if ('page' === $mode) {
            $columns[] = 'count(*) OVER () AS "_total"';
        }

        $sql = \sprintf('SELECT %s FROM quire.content c', 'count' === $mode ? 'count(*)' : implode(', ', $columns));

        if ('count' === $mode) {
            $params = [];
        }

        foreach ($this->joins as $join) {
            $sql .= ' '.$join['sql'];
            $params = [...$params, ...$join['params']];
        }

        $conditions = [['sql' => 1 === \count($this->types) ? 'c.type = ?' : \sprintf('c.type IN (%s)', implode(', ', array_fill(0, \count($this->types), '?'))), 'params' => $this->types]];
        $conditions = match ($this->trashed) {
            // The public condition excludes trashed items already.
            'exclude' => $this->appliesVisibility() ? $conditions : [...$conditions, ['sql' => 'c.deleted_at IS NULL', 'params' => []]],
            'include' => $conditions,
            'only' => [...$conditions, ['sql' => 'c.deleted_at IS NOT NULL', 'params' => []]],
        };

        if ($this->appliesVisibility()) {
            $conditions = [...$conditions, ...$this->runner->visibilityConditions($this->types)];
        }

        foreach ([...$conditions, ...$this->conditions] as $condition) {
            $params = [...$params, ...$condition['params']];
        }

        $sql .= ' WHERE '.implode(' AND ', array_column([...$conditions, ...$this->conditions], 'sql'));

        if ('count' === $mode) {
            return [$sql, $params];
        }

        $orders = match (true) {
            [] !== $this->orders => $this->orders,
            null !== $this->search => [['sql' => '"search_rank"', 'direction' => 'DESC']],
            default => $this->runner->defaultOrder($this->types),
        };
        // The ID breaks ties, so pages never overlap or skip items that sort the same.
        $orders[] = ['sql' => 'c.id', 'direction' => array_last($orders)['direction']];
        $sql .= ' ORDER BY '.implode(', ', array_map(static fn (array $order): string => \sprintf('%s %s NULLS LAST', $order['sql'], $order['direction']), $orders));

        if (null !== $this->limit) {
            $sql .= ' LIMIT '.$this->limit;
        }

        if (0 !== $this->offset) {
            $sql .= ' OFFSET '.$this->offset;
        }

        return [$sql, $params];
    }

    /**
     * Visitors see items that are published, or scheduled with a date that has passed, and not in the trash:
     * see {@see Content::isPublic()}.
     * The time is the statement's, not the transaction's (now()), so items published earlier in a long
     * transaction are visible to it.
     *
     * @internal
     *
     * @return Condition
     */
    public static function publicCondition(): array
    {
        return ['sql' => \sprintf("c.status IN ('%s', '%s') AND c.published_at <= statement_timestamp() AND c.deleted_at IS NULL", Status::PUBLISHED, Status::SCHEDULED), 'params' => []];
    }

    /**
     * @internal Used by {@see QueryRunner::paginate()}
     */
    public function page(int $page, int $perPage): self
    {
        $query = clone $this;
        $query->limit = $perPage;
        $query->offset = ($page - 1) * $perPage;

        return $query;
    }

    /**
     * @param Condition $condition
     */
    private function withCondition(array $condition): self
    {
        $query = clone $this;
        $query->conditions[] = $condition;

        return $query;
    }

    private function assertNotTemplate(string $method): void
    {
        if ($this->template) {
            throw new InvalidQueryException(\sprintf('%s isn\'t available in templates. Templates only show what visitors may see, and raw SQL belongs in a scope that an extension registers.', $method));
        }
    }
}
