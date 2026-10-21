<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * A field declared for a content type: its type, rules, and how admin interfaces should present it.
 *
 * @api
 */
final readonly class FieldDefinition
{
    /**
     * @param string           $owner      ID of the extension that declared it, or "core"
     * @param list<string|int> $choices    Allowed values, for {@see FieldType::Enum}
     * @param int|float|null   $min        Smallest allowed value, for numeric fields
     * @param int|float|null   $max        Largest allowed value, for numeric fields
     * @param int|null         $maxLength  Most characters, for string and text fields (and each item of a list)
     * @param bool             $sortable   Listings can be sorted by it; it gets a database index
     * @param bool             $filterable Listings can be filtered by it; it gets a database index
     * @param int              $order      Position among the type's fields in admin forms
     */
    public function __construct(
        public string $name,
        public FieldType $type,
        public string $owner,
        public mixed $default = null,
        public bool $required = false,
        public array $choices = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?int $maxLength = null,
        public bool $sortable = false,
        public bool $filterable = false,
        public ?string $label = null,
        public ?string $help = null,
        public ?string $group = null,
        public ?string $widget = null,
        public int $order = 0,
    ) {
    }

    public function isIndexed(): bool
    {
        return $this->sortable || $this->filterable;
    }

    /**
     * Converts accepted input to the stored form: dates and times to ISO 8601 text, whole numbers in float
     * fields to floats. Values that can't be converted are returned unchanged, for {@see validate()} to report.
     */
    public function normalize(mixed $value): mixed
    {
        return match ($this->type) {
            FieldType::Float => \is_int($value) ? (float) $value : $value,
            FieldType::Date => match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            },
            FieldType::DateTime => match (true) {
                $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                \is_string($value) && false !== ($parsed = $this->parseDateTime($value)) => $parsed->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * Why the (normalized) value isn't valid for this field, or null when it is.
     */
    public function validate(mixed $value): ?string
    {
        return match ($this->type) {
            FieldType::String, FieldType::Text => \is_string($value) ? $this->lengthError($value) : 'Expected text.',
            FieldType::Int => \is_int($value) ? $this->rangeError($value) : 'Expected a whole number.',
            FieldType::Float => \is_float($value) ? $this->rangeError($value) : 'Expected a number.',
            FieldType::Bool => \is_bool($value) ? null : 'Expected true or false.',
            FieldType::Enum => \in_array($value, $this->choices, true) ? null : \sprintf('Expected one of: %s.', implode(', ', array_map(static fn (string|int $choice): string => json_encode($choice, \JSON_THROW_ON_ERROR), $this->choices))),
            FieldType::List => $this->listError($value),
            FieldType::Date => \is_string($value) && $this->isDate($value) ? null : 'Expected a date such as "2026-12-24".',
            FieldType::DateTime => \is_string($value) && false !== $this->parseDateTime($value) ? null : 'Expected a date and time such as "2026-12-24T18:00:00+01:00".',
            FieldType::Json => $this->isJsonEncodable($value) ? null : 'Expected a value that can be stored as JSON.',
        };
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'owner' => $this->owner,
            'default' => $this->default,
            'required' => $this->required,
            'choices' => $this->choices,
            'min' => $this->min,
            'max' => $this->max,
            'max_length' => $this->maxLength,
            'sortable' => $this->sortable,
            'filterable' => $this->filterable,
            'label' => $this->label,
            'help' => $this->help,
            'group' => $this->group,
            'widget' => $this->widget,
            'order' => $this->order,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{name: string, type: string, owner: string, default: mixed, required: bool, choices: list<string|int>, min: int|float|null, max: int|float|null, max_length: ?int, sortable: bool, filterable: bool, label: ?string, help: ?string, group: ?string, widget: ?string, order: int} $field */
        $field = $data;

        return new self(
            name: $field['name'],
            type: FieldType::from($field['type']),
            owner: $field['owner'],
            default: $field['default'],
            required: $field['required'],
            choices: $field['choices'],
            min: $field['min'],
            max: $field['max'],
            maxLength: $field['max_length'],
            sortable: $field['sortable'],
            filterable: $field['filterable'],
            label: $field['label'],
            help: $field['help'],
            group: $field['group'],
            widget: $field['widget'],
            order: $field['order'],
        );
    }

    private function listError(mixed $value): ?string
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return 'Expected a list of texts.';
        }

        foreach ($value as $item) {
            $error = \is_string($item) ? $this->lengthError($item) : 'Expected a list of texts.';

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    private function lengthError(string $value): ?string
    {
        return null !== $this->maxLength && mb_strlen($value) > $this->maxLength ? \sprintf('At most %d characters.', $this->maxLength) : null;
    }

    private function rangeError(int|float $value): ?string
    {
        return match (true) {
            null !== $this->min && $value < $this->min => \sprintf('At least %s.', $this->min),
            null !== $this->max && $value > $this->max => \sprintf('At most %s.', $this->max),
            default => null,
        };
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value;
    }

    /**
     * Parses ISO 8601 date-times with an explicit offset, so a value never depends on the server's time zone.
     */
    private function parseDateTime(string $value): \DateTimeImmutable|false
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

    private function isJsonEncodable(mixed $value): bool
    {
        try {
            json_encode($value, \JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }
}
