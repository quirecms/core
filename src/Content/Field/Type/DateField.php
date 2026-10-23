<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;

/**
 * A calendar date, stored as "2026-12-24".
 *
 * @internal
 */
final class DateField extends AbstractFieldType
{
    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return false !== $date && $date->format('Y-m-d') === $value ? null : 'Expected a date such as "2026-12-24".';
    }

    /**
     * Only real dates: a malformed one is a mistake, not a value that matches nothing.
     */
    public function queryValue(mixed $value, FieldDefinition $field): ?string
    {
        $normalized = $this->normalize($value, $field);

        return \is_string($normalized) && null === $this->validate($normalized, $field) ? $normalized : null;
    }

    public function expected(FieldDefinition $field): string
    {
        return 'a date such as "2026-12-24"';
    }

    public function widget(FieldDefinition $field): string
    {
        return 'date';
    }
}
