<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * A whole number.
 *
 * @internal
 */
final class IntField extends AbstractFieldType
{
    public function options(): array
    {
        return ['min', 'max'];
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Number;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_int($value) ? $this->rangeError($value, $field) : 'Expected a whole number.';
    }
}
