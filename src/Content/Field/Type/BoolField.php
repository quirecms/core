<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * True or false.
 *
 * @internal
 */
final class BoolField extends AbstractFieldType
{
    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Boolean;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_bool($value) ? null : 'Expected true or false.';
    }
}
