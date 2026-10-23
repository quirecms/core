<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * A field type with the usual behaviour for its storage; override what differs.
 *
 *     final class ColorField extends AbstractFieldType
 *     {
 *         public function normalize(mixed $value, FieldDefinition $field): mixed
 *         {
 *             return \is_string($value) ? strtolower($value) : $value;
 *         }
 *
 *         public function validate(mixed $value, FieldDefinition $field): ?string
 *         {
 *             return \is_string($value) && preg_match('/^#[0-9a-f]{6}$/', $value) ? null : 'Expected a color such as "#ff8800".';
 *         }
 *     }
 *
 * @api
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function options(): array
    {
        return [];
    }

    public function definitionError(FieldDefinition $field): ?string
    {
        return null;
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Text;
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return $value;
    }

    /**
     * Compared as the stored form when the value is valid. Numbers and booleans also accept their text
     * forms ("5", "true"), as query strings have them.
     */
    public function queryValue(mixed $value, FieldDefinition $field): string|int|bool|null
    {
        return match ($this->storage($field)) {
            FieldStorage::Number => match (true) {
                \is_int($value) => $value,
                \is_float($value) => (string) $value,
                \is_string($value) && is_numeric($value) => trim($value),
                default => null,
            },
            FieldStorage::Boolean => match (true) {
                \is_bool($value) => $value,
                \in_array($value, [1, '1', 'true'], true) => true,
                \in_array($value, [0, '0', 'false'], true) => false,
                default => null,
            },
            default => $this->textQueryValue($value, $field),
        };
    }

    public function expected(FieldDefinition $field): string
    {
        return match ($this->storage($field)) {
            FieldStorage::Number => 'a number',
            FieldStorage::Boolean => 'true or false',
            default => 'text',
        };
    }

    public function widget(FieldDefinition $field): string
    {
        return match ($this->storage($field)) {
            FieldStorage::Number => 'number',
            FieldStorage::Boolean => 'checkbox',
            FieldStorage::TextList => 'tags',
            FieldStorage::Unindexed => 'textarea',
            FieldStorage::Relation => 'relation',
            FieldStorage::Text => 'text',
        };
    }

    /**
     * "At most 20 characters." when the text is longer than the field's maxLength.
     */
    protected function lengthError(string $value, FieldDefinition $field): ?string
    {
        return null !== $field->maxLength && mb_strlen($value) > $field->maxLength ? \sprintf('At most %d characters.', $field->maxLength) : null;
    }

    /**
     * "At least 1." or "At most 5." when the number is outside the field's min and max.
     */
    protected function rangeError(int|float $value, FieldDefinition $field): ?string
    {
        return match (true) {
            null !== $field->min && $value < $field->min => \sprintf('At least %s.', $field->min),
            null !== $field->max && $value > $field->max => \sprintf('At most %s.', $field->max),
            default => null,
        };
    }

    /**
     * The normalized value when it's valid; otherwise text as given, which simply matches nothing.
     */
    protected function textQueryValue(mixed $value, FieldDefinition $field): ?string
    {
        $normalized = $this->normalize($value, $field);

        if ((\is_string($normalized) || \is_int($normalized)) && null === $this->validate($normalized, $field)) {
            return (string) $normalized;
        }

        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }
}
