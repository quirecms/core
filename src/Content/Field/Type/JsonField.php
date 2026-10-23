<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * Any value that can be stored as JSON; can't be indexed.
 *
 * @internal
 */
final class JsonField extends AbstractFieldType
{
    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Unindexed;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        try {
            json_encode($value, \JSON_THROW_ON_ERROR);

            return null;
        } catch (\JsonException) {
            return 'Expected a value that can be stored as JSON.';
        }
    }

    public function widget(FieldDefinition $field): string
    {
        return 'json';
    }
}
