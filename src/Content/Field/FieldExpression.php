<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * The SQL expression that reads a field's value with its proper type.
 *
 * Indexes and queries must use exactly the same expression, or PostgreSQL won't use the index:
 * everything that sorts or filters by a field builds its SQL here.
 *
 * @api
 */
final class FieldExpression
{
    /**
     * E.g. `((fields ->> 'rating')::numeric)` for a numeric field.
     *
     * @param string $column The JSONB column, possibly qualified, e.g. "c.fields"
     */
    public static function value(FieldDefinition $field, string $column = 'fields'): string
    {
        $text = \sprintf("(%s ->> '%s')", $column, $field->name);

        return match (true) {
            $field->type->isNumeric(), FieldType::Enum === $field->type && self::hasOnlyIntChoices($field) => \sprintf('(%s::numeric)', $text),
            FieldType::Bool === $field->type => \sprintf('(%s::boolean)', $text),
            // Dates and date-times are stored as ISO 8601 text (date-times in UTC), so they sort as text.
            default => $text,
        };
    }

    /**
     * E.g. `(fields -> 'tags')`, the JSONB value, for containment queries on list fields.
     */
    public static function json(FieldDefinition $field, string $column = 'fields'): string
    {
        return \sprintf("(%s -> '%s')", $column, $field->name);
    }

    private static function hasOnlyIntChoices(FieldDefinition $field): bool
    {
        return array_all($field->choices, static fn (string|int $choice): bool => \is_int($choice));
    }

    private function __construct()
    {
    }
}
