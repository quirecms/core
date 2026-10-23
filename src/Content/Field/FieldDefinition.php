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
    /** The field type's name, e.g. "int", or "acme-maps.location" for an extension's */
    public string $type;

    /** @var class-string<FieldTypeInterface> */
    public string $typeClass;

    /**
     * @param FieldType|string                      $type       A core type, or the name of an extension's
     * @param string                                $owner      ID of the extension that declared it, or "core"
     * @param list<string|int>                      $choices    Allowed values, for {@see FieldType::Enum}
     * @param int|float|null                        $min        Smallest allowed value, for numeric fields
     * @param int|float|null                        $max        Largest allowed value, for numeric fields
     * @param int|null                              $maxLength  Most characters, for string and text fields (and each item of a list)
     * @param bool                                  $sortable   Listings can be sorted by it; it gets a database index
     * @param bool                                  $filterable Listings can be filtered by it; it gets a database index
     * @param string|null                           $widget     The admin widget, when not the type's own (see {@see widget()})
     * @param int                                   $order      Position among the type's fields in admin forms
     * @param array<string, mixed>                  $options    The type's own options
     * @param class-string<FieldTypeInterface>|null $typeClass  The type's class; found for core's types
     */
    public function __construct(
        public string $name,
        FieldType|string $type,
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
        public array $options = [],
        ?string $typeClass = null,
    ) {
        $this->type = $type instanceof FieldType ? $type->value : $type;
        $this->typeClass = $typeClass ?? FieldType::tryFrom($this->type)?->class() ?? throw new \LogicException(\sprintf('The field "%s" has the type "%s", which isn\'t one of core\'s: pass its class.', $name, $this->type));
    }

    public function isIndexed(): bool
    {
        return $this->sortable || $this->filterable;
    }

    /**
     * How the field's values are stored, compared and indexed.
     */
    public function storage(): FieldStorage
    {
        return $this->implementation()->storage($this);
    }

    /**
     * Converts accepted input to the stored form: e.g. dates and times to ISO 8601 text, whole numbers in
     * float fields to floats. Values that can't be converted are returned unchanged, for {@see validate()} to report.
     */
    public function normalize(mixed $value): mixed
    {
        return $this->implementation()->normalize($value, $this);
    }

    /**
     * Why the (normalized) value isn't valid for this field, or null when it is.
     */
    public function validate(mixed $value): ?string
    {
        return $this->implementation()->validate($value, $this);
    }

    /**
     * @see FieldTypeInterface::queryValue()
     */
    public function queryValue(mixed $value): string|int|bool|null
    {
        return $this->implementation()->queryValue($value, $this);
    }

    /**
     * @see FieldTypeInterface::expected()
     */
    public function expected(): string
    {
        return $this->implementation()->expected($this);
    }

    /**
     * The admin widget: the one declared, or the type's.
     */
    public function widget(): string
    {
        return $this->widget ?? $this->implementation()->widget($this);
    }

    /**
     * The field type, created once: types keep no state.
     */
    public function implementation(): FieldTypeInterface
    {
        return FieldTypeInstances::get($this->typeClass);
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
            'type' => $this->type,
            'type_class' => $this->typeClass,
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
            'options' => $this->options,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{name: string, type: string, type_class: class-string<FieldTypeInterface>, owner: string, default: mixed, required: bool, choices: list<string|int>, min: int|float|null, max: int|float|null, max_length: ?int, sortable: bool, filterable: bool, label: ?string, help: ?string, group: ?string, widget: ?string, order: int, options: array<string, mixed>} $field */
        $field = $data;

        return new self(
            name: $field['name'],
            type: $field['type'],
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
            options: $field['options'],
            typeClass: $field['type_class'],
        );
    }
}
