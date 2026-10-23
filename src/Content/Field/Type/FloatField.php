<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * A number; whole numbers are stored as floats.
 *
 * @internal
 */
final class FloatField extends AbstractFieldType
{
    public function options(): array
    {
        return ['min', 'max'];
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Number;
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return \is_int($value) ? (float) $value : $value;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_float($value) ? $this->rangeError($value, $field) : 'Expected a number.';
    }
}
