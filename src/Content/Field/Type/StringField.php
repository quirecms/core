<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;

/**
 * A single line of text.
 *
 * @internal
 */
final class StringField extends AbstractFieldType
{
    public function options(): array
    {
        return ['maxLength'];
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) ? $this->lengthError($value, $field) : 'Expected text.';
    }
}
