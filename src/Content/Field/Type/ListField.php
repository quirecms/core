<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;

/**
 * A list of texts, e.g. tags; filtered by what it contains.
 *
 * @internal
 */
final class ListField extends AbstractFieldType
{
    public function options(): array
    {
        return ['maxLength'];
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::TextList;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return 'Expected a list of texts.';
        }

        foreach ($value as $item) {
            $error = \is_string($item) ? $this->lengthError($item, $field) : 'Expected a list of texts.';

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }
}
