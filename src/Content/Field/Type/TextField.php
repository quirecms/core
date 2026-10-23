<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * Longer text, e.g. a summary; can't be indexed.
 *
 * @internal
 */
final class TextField extends AbstractFieldType
{
    public function options(): array
    {
        return ['maxLength'];
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Unindexed;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) ? $this->lengthError($value, $field) : 'Expected text.';
    }
}
