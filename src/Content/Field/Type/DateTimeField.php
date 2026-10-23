<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;

/**
 * A point in time, stored in UTC as "2026-12-24T18:00:00+00:00" so it sorts as text.
 *
 * @internal
 */
final class DateTimeField extends AbstractFieldType
{
    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            \is_string($value) && false !== ($parsed = $this->parse($value)) => $parsed->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            default => $value,
        };
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) && false !== $this->parse($value) ? null : 'Expected a date and time such as "2026-12-24T18:00:00+01:00".';
    }

    public function queryValue(mixed $value, FieldDefinition $field): ?string
    {
        $normalized = $this->normalize($value, $field);

        return \is_string($normalized) && null === $this->validate($normalized, $field) ? $normalized : null;
    }

    public function expected(FieldDefinition $field): string
    {
        return 'a date and time with an offset, such as "2026-12-24T18:00:00+01:00"';
    }

    public function widget(FieldDefinition $field): string
    {
        return 'datetime';
    }

    /**
     * ISO 8601 date-times with an explicit offset, so a value never depends on the server's time zone.
     */
    private function parse(string $value): \DateTimeImmutable|false
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return false;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }
    }
}
