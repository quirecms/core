<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Container\ContainerInterface;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Level;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Field\Type\RelationField;
use Quire\Content\Internal\RelationFieldsPass;
use Quire\Content\Internal\Row;
use Quire\Content\Query\ContentPage;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Query\RelationLoaderInterface;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Permission\Internal\Viewer;
use Quire\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Markup;

/**
 * Runs content queries: applies the visibility rules, scopes and relations that extensions registered.
 *
 * @internal Use {@see ContentRepository::query()}
 *
 * @phpstan-type Registration array{class: class-string, types: ?list<string>, owner: string}
 * @phpstan-type Config array{scopes: array<string, Registration>, visibility: list<Registration>, relations: array<string, Registration>}
 *
 * @phpstan-import-type Condition from ContentQuery
 */
final readonly class QueryRunner
{
    /** Compiled by {@see \Quire\Extend\Query}. */
    public const string PARAMETER = 'quire.content.query';

    private string $columns;

    /** @var array<string, int> */
    private array $columnKeys;

    /** @var Config */
    private array $config;

    private QueryFields $fields;

    /** What countBy() counts: columns with few values */
    private const array FACET_COLUMNS = ['type', 'status', 'locale', 'author_id', 'parent_id'];

    /** Periods of dates, and how a period is written: "2026", "2026-10", "2026-W43", "2026-10-26" */
    private const array PERIODS = ['year' => 'YYYY', 'month' => 'YYYY-MM', 'week' => 'IYYY-"W"IW', 'day' => 'YYYY-MM-DD'];

    /**
     * @param array<string, mixed> $config
     * @param ContainerInterface   $services The scope, visibility and relation classes, set by {@see QueryServicesPass}
     */
    public function __construct(
        private Connection $connection,
        private ContentTypeRegistry $types,
        #[Autowire(param: self::PARAMETER)]
        array $config,
        private ContainerInterface $services,
        private Viewer $viewer,
        private Settings $settings,
        private ContentChecker $checker,
        /** @var array<string, array<string, list<array{type: string, field: string}>>> Inverses of relation fields: target type → name → sources */
        #[Autowire(param: RelationFieldsPass::INVERSES_PARAMETER)]
        private array $inverses = [],
    ) {
        /** @var Config $config */
        $config = [...['scopes' => [], 'visibility' => [], 'relations' => []], ...$config];
        $this->config = $config;
        $this->fields = new QueryFields($types);
        $columns = explode(', ', ContentRepository::COLUMNS);
        $this->columns = implode(', ', array_map(static fn (string $column): string => 'c.'.$column, $columns));
        $this->columnKeys = array_flip($columns);
    }

    /**
     * @param list<string> $types
     */
    public function query(array $types, bool $template = false): ContentQuery
    {
        $types = array_values(array_unique($types));

        if ([] === $types) {
            throw new InvalidQueryException('A content query needs at least one content type.');
        }

        foreach ($types as $type) {
            $this->types->get($type);
        }

        return new ContentQuery($this, $types, $template);
    }

    public function fields(): QueryFields
    {
        return $this->fields;
    }

    public function columns(): string
    {
        return $this->columns;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function scope(ContentQuery $query, string $name, array $arguments): ContentQuery
    {
        $scope = $this->config['scopes'][$name] ?? throw new InvalidQueryException(\sprintf('Unknown scope "%s". %s', $name, [] === $this->config['scopes'] ? 'No scopes are registered.' : 'Registered: '.implode(', ', array_keys($this->config['scopes'])).'.'));
        $this->assertTypes($query->types(), $scope['types'], \sprintf('The scope "%s" (from "%s")', $name, $scope['owner']));

        $callable = $this->services->get($scope['class']);
        \assert(\is_callable($callable));

        try {
            $result = $callable($query, ...$arguments);
        } catch (\ArgumentCountError|\TypeError $e) {
            // Wrong arguments from a template or caller, not a bug in the scope.
            if (!str_contains($e->getMessage(), '__invoke()')) {
                throw $e;
            }

            throw new InvalidQueryException(\sprintf('Wrong arguments for the scope "%s": %s', $name, $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (!$result instanceof ContentQuery) {
            throw new \LogicException(\sprintf('The scope "%s" (%s) must return the query it was given, refined.', $name, $scope['class']));
        }

        return $result;
    }

    /**
     * What visitors may see: the public rule, then the rules extensions registered for the queried types.
     *
     * @param list<string> $types
     *
     * @return list<Condition>
     */
    public function visibilityConditions(array $types): array
    {
        $conditions = [ContentQuery::publicCondition()];

        // Types the viewer may not view, e.g. members-only ones for visitors, aren't listed at all.
        $hidden = array_values(array_filter($types, fn (string $type): bool => !$this->viewer->canViewType($type)));

        if ([] !== $hidden) {
            $conditions[] = ['sql' => \sprintf('c.type NOT IN (%s)', implode(', ', array_fill(0, \count($hidden), '?'))), 'params' => $hidden];
        }

        foreach ($this->config['visibility'] as $rule) {
            $ruleTypes = null === $rule['types'] ? $types : array_values(array_intersect($types, $rule['types']));

            if ([] === $ruleTypes) {
                continue;
            }

            $callable = $this->services->get($rule['class']);
            \assert(\is_callable($callable));
            $result = $callable($this->query($ruleTypes)->withoutVisibilityScope());

            if (!$result instanceof ContentQuery) {
                throw new \LogicException(\sprintf('The visibility rule %s must return the query it was given, refined.', $rule['class']));
            }

            ['conditions' => $ruleConditions, 'rest' => $rest] = $result->conditions();

            if ($rest) {
                throw new \LogicException(\sprintf('The visibility rule %s can only add conditions (where(), whereSql()), not joins, selects, order or limits.', $rule['class']));
            }

            if ([] === $ruleConditions) {
                continue;
            }

            $sql = implode(' AND ', array_column($ruleConditions, 'sql'));
            $params = array_merge(...array_column($ruleConditions, 'params'));

            // A rule for some of the queried types leaves the others alone.
            if (\count($ruleTypes) < \count($types)) {
                $sql = \sprintf('(c.type NOT IN (%s) OR (%s))', implode(', ', array_fill(0, \count($ruleTypes), '?')), $sql);
                $params = [...$ruleTypes, ...$params];
            }

            $conditions[] = ['sql' => $sql, 'params' => $params];
        }

        return $conditions;
    }

    /**
     * @param list<string> $types
     *
     * @return non-empty-list<array{sql: string, direction: 'ASC'|'DESC'}>
     */
    public function defaultOrder(array $types): array
    {
        if (1 !== \count($types)) {
            return [['sql' => 'c.published_at', 'direction' => 'DESC']];
        }

        $type = $this->types->get($types[0]);

        return [['sql' => $this->fields->sortExpression($types, $type->sortField), 'direction' => 'desc' === $type->sortDirection ? 'DESC' : 'ASC']];
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->config['relations'][$name]);
    }

    /**
     * @param list<string> $types
     */
    public function assertRelation(array $types, string $name): void
    {
        if (!isset($this->config['relations'][$name]) && null !== $this->fieldRelation($types, $name)) {
            return;
        }

        $relation = $this->config['relations'][$name] ?? throw new InvalidQueryException(\sprintf('Unknown relation "%s". Registered: %s, and the relation fields of the queried types and their inverses.', $name, implode(', ', array_keys($this->config['relations']))));
        $this->assertTypes($types, $relation['types'], \sprintf('The relation "%s" (from "%s")', $name, $relation['owner']));
    }

    /**
     * @return list<Content>
     */
    public function fetch(ContentQuery $query): array
    {
        [$sql, $params] = $query->toSql();

        return $this->loadRelations($query, $this->addSnippets($query, array_map($this->hydrate(...), $this->rows($sql, $params))));
    }

    /**
     * What a facet of {@see ContentQuery::countBy()} groups by: an SQL expression, its parameters, and a
     * join (with its parameters) for properties that give an item several values.
     *
     * @param list<string> $types
     *
     * @return array{select: string, params: list<mixed>, join: string, joinParams: list<mixed>}
     *
     * @throws InvalidQueryException
     */
    public function facet(array $types, string $property, ?string $per): array
    {
        $dates = ['published_at', 'created_at', 'updated_at'];

        if (null !== $per && (!\in_array($property, $dates, true) || !isset(self::PERIODS[$per]))) {
            throw new InvalidQueryException(\in_array($property, $dates, true) ? \sprintf('Count dates per year, month, week or day, not per "%s".', $per) : \sprintf('Only dates (%s) are counted per period.', implode(', ', $dates)));
        }

        return match (true) {
            \in_array($property, $dates, true) => null === $per
                ? throw new InvalidQueryException(\sprintf('Count "%s" per year, month, week or day.', $property)) /* In the site's time zone: a post of 23:30 on the 31st belongs to the month its readers saw. */ : ['select' => \sprintf('to_char(date_trunc(?, c.%s AT TIME ZONE ?), ?)', $property), 'params' => [$per, $this->timezone(), self::PERIODS[$per]], 'join' => '', 'joinParams' => []],
            \in_array($property, self::FACET_COLUMNS, true) => ['select' => \sprintf('c.%s::text', $property), 'params' => [], 'join' => '', 'joinParams' => []],
            str_starts_with($property, 'fields.') => ['params' => [], ...$this->fields->facet($types, substr($property, \strlen('fields.')))],
            'checks' === $property => ['select' => 'k.check_id', 'params' => [], 'join' => 'JOIN quire.content_check_result k ON k.content_id = c.id AND k.check_id = ANY(CAST(? AS text[]))', 'joinParams' => ['{'.implode(',', array_map(static fn (string $id): string => '"'.$id.'"', $this->activeChecks())).'}']],
            default => throw new InvalidQueryException(\sprintf('Content can\'t be counted by "%s". Use one of: %s; %s per period; a filterable field as "fields.<name>"; or checks.', $property, implode(', ', self::FACET_COLUMNS), implode(', ', $dates))),
        };
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<int|string, int>
     */
    public function facetCounts(string $sql, array $params): array
    {
        $counts = [];

        foreach ($this->connection->fetchAllNumeric($sql, $params, $this->parameterTypes($params)) as [$value, $count]) {
            if (\is_scalar($value) && is_numeric($count)) {
                $counts[\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value] = (int) $count;
            }
        }

        return $counts;
    }

    /**
     * The checks that are on, at this level or of this ID.
     *
     * @return list<string>
     */
    public function activeChecks(?Level $level = null, ?string $check = null): array
    {
        return array_values(array_map(
            static fn (array $definition): string => $definition['id'],
            array_filter($this->checker->checks(), static fn (array $definition): bool => null !== $definition['level'] && (!$level instanceof Level || $level === $definition['level']) && (null === $check || $check === $definition['id'])),
        ));
    }

    private function timezone(): string
    {
        $timezone = $this->settings->get('site.timezone');

        return \is_string($timezone) && '' !== $timezone ? $timezone : 'UTC';
    }

    public function count(ContentQuery $query): int
    {
        [$sql, $params] = $query->toSql('count');
        $count = $this->connection->fetchOne($sql, $params, $this->parameterTypes($params));

        return is_numeric($count) ? (int) $count : 0;
    }

    public function paginate(ContentQuery $query, int $page, int $perPage): ContentPage
    {
        // One query returns the page and the total: count(*) OVER () counts all matching rows before LIMIT.
        [$sql, $params] = $query->page($page, $perPage)->toSql('page');
        $rows = $this->rows($sql, $params);
        $total = [] === $rows ? ($page > 1 ? $this->count($query) : 0) : new Row($rows[0])->int('_total');

        $items = $this->loadRelations($query, $this->addSnippets($query, array_map(function (array $row): Content {
            unset($row['_total']);

            return $this->hydrate($row);
        }, $rows)));

        return new ContentPage($items, $page, $perPage, $total);
    }

    /**
     * Loads each of the query's relations with one call to its loader for all items.
     *
     * @param list<Content> $items
     *
     * @return list<Content>
     */
    public function loadRelations(ContentQuery $query, array $items): array
    {
        if ([] === $items) {
            return [];
        }

        foreach ($query->relations() as $name) {
            if (isset($this->config['relations'][$name])) {
                $loader = $this->services->get($this->config['relations'][$name]['class']);
                \assert($loader instanceof RelationLoaderInterface);
                $values = $loader->load($items, $query);
            } else {
                $relation = $this->fieldRelation($query->types(), $name) ?? throw new \LogicException(\sprintf('The relation "%s" was checked when it was added.', $name));
                $values = 'field' === $relation['kind'] ? $this->linkedItems($items, $query, $name) : $this->linkingItems($items, $query, $relation['sources']);
            }

            $items = array_map(static fn (Content $item): Content => $item->withAttributes([$name => $values[$item->id] ?? null]), $items);
        }

        return $items;
    }

    /**
     * The search snippets of the items, with one query for all of them, when the query asks for them.
     *
     * @param list<Content> $items
     *
     * @return list<Content>
     */
    private function addSnippets(ContentQuery $query, array $items): array
    {
        $search = $query->snippetSearch();

        if (null === $search || [] === $items) {
            return $items;
        }

        [$sql, $params] = SearchSql::snippets($search);
        $snippets = [];

        foreach ($this->connection->fetchAllAssociative($sql, [...$params, '{'.implode(',', array_map(static fn (Content $item): string => $item->id, $items)).'}']) as $row) {
            $row = new Row($row);
            $snippets[$row->string('id')] = new Markup(SearchSql::html($row->string('snippet')), 'UTF-8');
        }

        return array_map(static fn (Content $item): Content => $item->withAttributes(['search_snippet' => $snippets[$item->id] ?? null]), $items);
    }

    /**
     * A relation that comes with the content types: a relation field all the queried types declare, or the
     * inverse of other types' relation fields that link to all of them.
     *
     * @param list<string> $types
     *
     * @return array{kind: 'field'}|array{kind: 'inverse', sources: list<array{type: string, field: string}>}|null
     */
    private function fieldRelation(array $types, string $name): ?array
    {
        if ([] !== $types && array_all($types, fn (string $type): bool => $this->types->has($type) && FieldStorage::Relation === $this->types->get($type)->field($name)?->storage())) {
            return ['kind' => 'field'];
        }

        if ([] === $types || !array_all($types, fn (string $type): bool => isset($this->inverses[$type][$name]))) {
            return null;
        }

        $sources = array_merge(...array_map(fn (string $type): array => $this->inverses[$type][$name], $types));

        return ['kind' => 'inverse', 'sources' => array_values(array_unique($sources, \SORT_REGULAR))];
    }

    /**
     * The items each item's relation field links to, in its order: one query for all, with the visibility
     * rules of the query (links to items the viewer can't see are left out).
     *
     * @param non-empty-list<Content> $items
     *
     * @return array<string, list<Content>|Content|null>
     */
    private function linkedItems(array $items, ContentQuery $query, string $name): array
    {
        $ids = [];
        $targetTypes = [];

        foreach ($items as $item) {
            $field = $this->types->get($item->type)->field($name);
            $value = $item->fields[$name] ?? null;
            $ids[$item->id] = array_values(array_filter(\is_array($value) ? $value : [$value], \is_string(...)));
            $targetTypes = [...$targetTypes, ...($field instanceof \Quire\Content\Field\FieldDefinition ? RelationField::targetTypes($field) : [])];
        }

        $all = array_values(array_unique(array_merge(...array_values($ids))));
        $found = [];

        if ([] !== $all && [] !== $targetTypes) {
            foreach ($query->related(...array_values(array_unique($targetTypes)))->where('id', 'in', $all)->get() as $linked) {
                $found[$linked->id] = $linked;
            }
        }

        $values = [];

        foreach ($items as $item) {
            $linked = array_values(array_filter(array_map(static fn (string $id): ?Content => $found[$id] ?? null, $ids[$item->id])));
            $field = $this->types->get($item->type)->field($name);
            $values[$item->id] = $field instanceof \Quire\Content\Field\FieldDefinition && RelationField::isMultiple($field) ? $linked : ($linked[0] ?? null);
        }

        return $values;
    }

    /**
     * The items that link to each item through the given relation fields, in their types' order, with the
     * visibility rules of the query.
     *
     * @param non-empty-list<Content>                  $items
     * @param list<array{type: string, field: string}> $sources
     *
     * @return array<string, list<Content>>
     */
    private function linkingItems(array $items, ContentQuery $query, array $sources): array
    {
        $values = array_fill_keys(array_map(static fn (Content $item): string => $item->id, $items), []);
        $pairs = implode(' OR ', array_fill(0, \count($sources), '(s.type = ? AND r.field = ?)'));
        $links = $this->connection->fetchAllAssociative(
            'SELECT r.target_id, r.source_id FROM quire.content_relation r JOIN quire.content s ON s.id = r.source_id WHERE r.target_id = ANY(CAST(? AS uuid[])) AND ('.$pairs.')',
            ['{'.implode(',', array_keys($values)).'}', ...array_merge(...array_map(static fn (array $source): array => [$source['type'], $source['field']], $sources))],
        );

        if ([] === $links) {
            return $values;
        }

        $byTarget = [];

        foreach ($links as $link) {
            $byTarget[new Row($link)->string('source_id')][] = new Row($link)->string('target_id');
        }

        $types = array_values(array_unique(array_column($sources, 'type')));

        foreach ($query->related(...$types)->where('id', 'in', array_keys($byTarget))->get() as $source) {
            foreach ($byTarget[$source->id] ?? [] as $target) {
                $values[$target][] = $source;
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        return $this->connection->fetchAllAssociative($sql, $params, $this->parameterTypes($params));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Content
    {
        $attributes = array_diff_key($row, $this->columnKeys);
        $content = ContentRepository::hydrate($row);

        return [] === $attributes ? $content : $content->withAttributes($attributes);
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<ParameterType>
     */
    private function parameterTypes(array $params): array
    {
        return array_map(static fn (mixed $param): ParameterType => match (true) {
            \is_int($param) => ParameterType::INTEGER,
            \is_bool($param) => ParameterType::BOOLEAN,
            null === $param => ParameterType::NULL,
            default => ParameterType::STRING,
        }, $params);
    }

    /**
     * @param list<string>      $types
     * @param list<string>|null $supported
     */
    private function assertTypes(array $types, ?array $supported, string $what): void
    {
        $unsupported = null === $supported ? [] : array_diff($types, $supported);

        if ([] !== $unsupported) {
            throw new InvalidQueryException(\sprintf('%s doesn\'t apply to %s; only to %s.', $what, implode(', ', $unsupported), implode(', ', $supported)));
        }
    }
}
