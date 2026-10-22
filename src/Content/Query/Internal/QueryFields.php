<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldExpression;
use Quire\Content\Field\FieldType;
use Quire\Content\Internal\Row;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;

/**
 * Turns the fields and columns a query filters and sorts by into SQL, checking that they may be used.
 *
 * Only indexed things can be filtered and sorted by: fields declared filterable or sortable, and core's
 * columns. So a listing never needs a full table scan because of a typo in a template.
 *
 * @internal
 *
 * @phpstan-type Condition array{sql: string, params: list<mixed>}
 */
final readonly class QueryFields
{
    /** Columns that can be filtered by, and the kind of value each holds. */
    private const array FILTER_COLUMNS = [
        'id' => 'uuid',
        'slug' => 'string',
        'locale' => 'string',
        'status' => 'string',
        'parent_id' => 'uuid',
        'author_id' => 'uuid',
        'translation_group_id' => 'uuid',
        'published_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    private const array OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'in', 'not in', 'contains', 'contains any'];

    public function __construct(
        private ContentTypeRegistry $types,
    ) {
    }

    /**
     * @param list<string> $types
     *
     * @return Condition
     *
     * @throws InvalidQueryException
     */
    public function condition(array $types, string $field, string $operator, mixed $value): array
    {
        $operator = strtolower(trim($operator));
        $operator = '<>' === $operator ? '!=' : $operator;

        if (!\in_array($operator, self::OPERATORS, true)) {
            throw new InvalidQueryException(\sprintf('Unknown operator "%s" for "%s". Use one of: %s.', $operator, $field, implode(', ', self::OPERATORS)));
        }

        if (str_starts_with($field, 'fields.')) {
            $definition = $this->field($types, substr($field, \strlen('fields.')), 'filtered');

            if (FieldType::List === $definition->type) {
                return $this->containment($field, FieldExpression::json($definition, 'c.fields'), $operator, $value);
            }

            return $this->comparison($field, FieldExpression::value($definition, 'c.fields'), $operator, $value, $definition);
        }

        if (!isset(self::FILTER_COLUMNS[$field])) {
            throw new InvalidQueryException(\sprintf('Content can\'t be filtered by "%s". Use one of: %s, or a filterable field as "fields.<name>".', $field, implode(', ', array_keys(self::FILTER_COLUMNS))));
        }

        return $this->comparison($field, 'c.'.$field, $operator, $value, self::FILTER_COLUMNS[$field]);
    }

    /**
     * @param list<string> $types
     * @param list<string> $aliases Names of the query's selected values, which can be sorted by too
     *
     * @throws InvalidQueryException
     */
    public function sortExpression(array $types, string $field, array $aliases = []): string
    {
        return match (true) {
            str_starts_with($field, 'fields.') => FieldExpression::value($this->field($types, substr($field, \strlen('fields.')), 'sorted'), 'c.fields'),
            \in_array($field, ContentTypeDefinition::SORT_COLUMNS, true) => 'c.'.$field,
            \in_array($field, $aliases, true) => \sprintf('"%s"', $field),
            default => throw new InvalidQueryException(\sprintf('Content can\'t be sorted by "%s". Use one of: %s, a sortable field as "fields.<name>", or a selected value.', $field, implode(', ', ContentTypeDefinition::SORT_COLUMNS))),
        };
    }

    /**
     * The field as declared by every type of the query. It must be declared the same way by each.
     *
     * A field declared sortable or filterable has an index, and the index serves both: a B-tree on the value
     * sorts and filters, and the GIN index of a list field filters (lists can't be sorted).
     *
     * @param list<string>        $types
     * @param 'filtered'|'sorted' $use
     */
    private function field(array $types, string $name, string $use): FieldDefinition
    {
        $found = null;
        $usable = static fn (FieldDefinition $field): bool => $field->isIndexed() && ('filtered' === $use || $field->type->canBeSorted());

        foreach ($types as $type) {
            $definition = $this->types->get($type)->field($name);

            if (!$definition instanceof FieldDefinition || !$usable($definition)) {
                $declared = array_keys(array_filter($this->types->get($type)->fields, $usable));

                throw new InvalidQueryException(\sprintf('%s can\'t be %s by "fields.%s": %s. %s', ucfirst($type), $use, $name, match (true) {
                    !$definition instanceof FieldDefinition => 'the field isn\'t declared', $definition->isIndexed() => 'lists can\'t be sorted', default => 'the field isn\'t declared with "sortable: true" or "filterable: true", so it has no index',
                }, [] === $declared ? \sprintf('No field of "%s" can be %s.', $type, $use) : \sprintf('Fields that can be %s: %s.', $use, implode(', ', $declared))));
            }

            if ($found instanceof FieldDefinition && FieldExpression::value($found) !== FieldExpression::value($definition)) {
                throw new InvalidQueryException(\sprintf('The field "%s" is declared with different types by the queried content types, so they can\'t be filtered or sorted by it together.', $name));
            }

            $found = $definition;
        }

        return $found ?? throw new \LogicException('A content query has at least one type.');
    }

    /**
     * @return Condition
     */
    private function containment(string $field, string $expression, string $operator, mixed $value): array
    {
        $given = \is_array($value) ? $value : [$value];
        $values = array_values(array_filter($given, \is_string(...)));

        if (!\in_array($operator, ['contains', 'contains any'], true)) {
            throw new InvalidQueryException(\sprintf('"%s" is a list: filter it with "contains" (has all of the values) or "contains any".', $field));
        }

        if ([] === $values || \count($values) !== \count($given)) {
            throw new InvalidQueryException(\sprintf('Filtering "%s" needs a text or a non-empty list of texts.', $field));
        }

        // Containment (@>) is what the GIN index serves; "any" is an OR of single containments.
        if ('contains' === $operator) {
            return ['sql' => \sprintf('%s @> CAST(? AS jsonb)', $expression), 'params' => [json_encode($values, \JSON_THROW_ON_ERROR)]];
        }

        return [
            'sql' => '('.implode(' OR ', array_fill(0, \count($values), \sprintf('%s @> CAST(? AS jsonb)', $expression))).')',
            'params' => array_map(static fn (string $item): string => json_encode([$item], \JSON_THROW_ON_ERROR), $values),
        ];
    }

    /**
     * @param 'uuid'|'string'|'timestamp'|FieldDefinition $kind
     *
     * @return Condition
     */
    private function comparison(string $field, string $expression, string $operator, mixed $value, string|FieldDefinition $kind): array
    {
        if (\in_array($operator, ['contains', 'contains any'], true)) {
            throw new InvalidQueryException(\sprintf('"%s" isn\'t a list: "%s" only applies to list fields.', $field, $operator));
        }

        if (\in_array($operator, ['in', 'not in'], true)) {
            if (!\is_array($value)) {
                throw new InvalidQueryException(\sprintf('"%s" needs a list of values for "%s".', $operator, $field));
            }

            $values = array_values(array_filter(array_map(fn (mixed $item): mixed => $this->value($field, $item, $kind), array_values($value)), static fn (mixed $item): bool => !$item instanceof NoMatch));

            if ([] === $values) {
                return ['sql' => 'in' === $operator ? 'FALSE' : 'TRUE', 'params' => []];
            }

            return ['sql' => \sprintf('%s %s (%s)', $expression, strtoupper($operator), implode(', ', array_fill(0, \count($values), '?'))), 'params' => $values];
        }

        if (null === $value) {
            return match ($operator) {
                '=' => ['sql' => $expression.' IS NULL', 'params' => []],
                '!=' => ['sql' => $expression.' IS NOT NULL', 'params' => []],
                default => throw new InvalidQueryException(\sprintf('"%s" can\'t be compared with null using "%s".', $field, $operator)),
            };
        }

        if ('uuid' === $kind && !\in_array($operator, ['=', '!='], true)) {
            throw new InvalidQueryException(\sprintf('"%s" holds IDs: compare it with "=", "!=", "in" or "not in".', $field));
        }

        $converted = $this->value($field, $value, $kind);

        // A malformed ID matches no item, as when loading one by ID.
        if ($converted instanceof NoMatch) {
            return '=' === $operator ? ['sql' => 'FALSE', 'params' => []] : ['sql' => $expression.' IS NOT NULL', 'params' => []];
        }

        return ['sql' => \sprintf('%s %s ?', $expression, $operator), 'params' => [$converted]];
    }

    /**
     * Checks a value against what the field or column holds and converts it to what the SQL compares.
     *
     * @param 'uuid'|'string'|'timestamp'|FieldDefinition $kind
     */
    private function value(string $field, mixed $value, string|FieldDefinition $kind): mixed
    {
        $type = $kind instanceof FieldDefinition ? $kind->type : null;
        $numeric = $kind instanceof FieldDefinition && FieldExpression::isNumeric($kind);

        $converted = match (true) {
            'uuid' === $kind => \is_string($value) ? (Row::isUuid($value) ? $value : new NoMatch()) : null,
            $numeric => match (true) {
                \is_int($value) => $value,
                \is_float($value) => (string) $value,
                \is_string($value) && is_numeric($value) => trim($value),
                default => null,
            },
            FieldType::Bool === $type => match (true) {
                \is_bool($value) => $value,
                \in_array($value, [1, '1', 'true'], true) => true,
                \in_array($value, [0, '0', 'false'], true) => false,
                default => null,
            },
            FieldType::Date === $type, FieldType::DateTime === $type => $this->fieldDate($kind, $value),
            'timestamp' === $kind => $this->timestamp($value),
            default => \is_string($value) || \is_int($value) ? (string) $value : null,
        };

        return $converted ?? throw new InvalidQueryException(\sprintf('"%s" can\'t be compared with %s: %s.', $field, get_debug_type($value), $this->expected($kind)));
    }

    private function fieldDate(FieldDefinition $field, mixed $value): ?string
    {
        $normalized = $field->normalize($value);

        return \is_string($normalized) && null === $field->validate($normalized) ? $normalized : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if (!\is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param 'uuid'|'string'|'timestamp'|FieldDefinition $kind
     */
    private function expected(string|FieldDefinition $kind): string
    {
        return match (true) {
            'uuid' === $kind => 'expected an ID',
            'timestamp' === $kind => 'expected a date and time',
            !$kind instanceof FieldDefinition => 'expected text',
            FieldExpression::isNumeric($kind) => 'expected a number',
            FieldType::Bool === $kind->type => 'expected true or false',
            FieldType::Date === $kind->type => 'expected a date such as "2026-12-24"',
            FieldType::DateTime === $kind->type => 'expected a date and time with an offset, such as "2026-12-24T18:00:00+01:00"',
            default => 'expected text',
        };
    }
}
