<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * One of a fixed list of choices. Choices that are all whole numbers are compared as numbers.
 *
 * @internal
 */
final class EnumField extends AbstractFieldType
{
    public function options(): array
    {
        return ['choices'];
    }

    public function definitionError(FieldDefinition $field): ?string
    {
        return [] === $field->choices ? 'Fields of type "enum" need a list of choices.' : null;
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return array_all($field->choices, static fn (string|int $choice): bool => \is_int($choice)) ? FieldStorage::Number : FieldStorage::Text;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \in_array($value, $field->choices, true) ? null : \sprintf('Expected one of: %s.', implode(', ', array_map(static fn (string|int $choice): string => json_encode($choice, \JSON_THROW_ON_ERROR), $field->choices)));
    }

    public function widget(FieldDefinition $field): string
    {
        return 'select';
    }
}
