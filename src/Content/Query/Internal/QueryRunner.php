<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Container\ContainerInterface;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Internal\Row;
use Quire\Content\Query\ContentPage;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Query\RelationLoaderInterface;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Permission\Internal\Viewer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        $relation = $this->config['relations'][$name] ?? throw new InvalidQueryException(\sprintf('Unknown relation "%s". Registered: %s.', $name, implode(', ', array_keys($this->config['relations']))));
        $this->assertTypes($types, $relation['types'], \sprintf('The relation "%s" (from "%s")', $name, $relation['owner']));
    }

    /**
     * @return list<Content>
     */
    public function fetch(ContentQuery $query): array
    {
        [$sql, $params] = $query->toSql();

        return $this->loadRelations($query, array_map($this->hydrate(...), $this->rows($sql, $params)));
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

        $items = $this->loadRelations($query, array_map(function (array $row): Content {
            unset($row['_total']);

            return $this->hydrate($row);
        }, $rows));

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
            $loader = $this->services->get($this->config['relations'][$name]['class']);
            \assert($loader instanceof RelationLoaderInterface);
            $values = $loader->load($items, $query);
            $items = array_map(static fn (Content $item): Content => $item->withAttributes([$name => $values[$item->id] ?? null]), $items);
        }

        return $items;
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
