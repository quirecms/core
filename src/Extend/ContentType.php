<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldType;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;

/**
 * Registers a content type, or adds capabilities and fields to one that another extension registered.
 *
 *     new Extend\ContentType('product')
 *         ->label('Product', 'Products')
 *         ->capabilities(Capability::REVISIONS, Capability::LISTABLE, Capability::SEARCHABLE)
 *         ->field('price', 'float', required: true, min: 0, sortable: true)
 *         ->field('color', 'enum', choices: ['red', 'blue'], filterable: true)
 *         ->permalink('/products/{slug}')
 *         ->sortBy('title');
 *
 *     Extend\ContentType::existing('post')
 *         ->addCapabilities('commentable')
 *         ->field('subtitle', 'string', maxLength: 200);
 *
 * Read the registered types with {@see ContentTypeRegistry}.
 *
 * @api
 */
final class ContentType implements ExtenderInterface
{
    private const string ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private const string CAPABILITY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const string FIELD_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** B-tree index entries must stay well below PostgreSQL's limit of about 2700 bytes. */
    private const int MAX_INDEXED_LENGTH = 1000;

    private const int DEFAULT_INDEXED_LENGTH = 255;

    /** Segments of static text and placeholders, e.g. "/blog/{year}/{slug}". */
    private const string PERMALINK_PATTERN = '#^(?:/(?:[a-z0-9._~-]|\{[a-z]+\})+)+$#';

    private const array PLACEHOLDERS = ['slug', 'path', 'id', 'year', 'month', 'day'];

    /** At least one of these makes each item's URL unique. */
    private const array IDENTIFYING_PLACEHOLDERS = ['slug', 'path', 'id'];

    private const array SORT_FIELDS = ['published_at', 'created_at', 'updated_at', 'title', 'position'];

    private ?string $label = null;

    private ?string $pluralLabel = null;

    /** @var list<string>|null */
    private ?array $capabilities = null;

    /** @var list<string> */
    private array $addedCapabilities = [];

    private ?string $permalink = null;

    private ?string $sortField = null;

    private string $sortDirection = 'asc';

    /** @var list<array<string, mixed>> */
    private array $fields = [];

    public function __construct(
        private readonly string $id,
        private readonly bool $existing = false,
    ) {
    }

    /**
     * Changes a type registered by core or by another extension. Only capabilities and fields can be added.
     */
    public static function existing(string $id): self
    {
        return new self($id, existing: true);
    }

    /**
     * Names shown in admin interfaces. Defaults to the ID, e.g. "Product" and "Products".
     */
    public function label(string $singular, ?string $plural = null): self
    {
        $this->label = $singular;
        $this->pluralLabel = $plural;

        return $this;
    }

    /**
     * Replaces the type's capabilities. See {@see Capability} for those core understands.
     */
    public function capabilities(string ...$capabilities): self
    {
        $this->capabilities = array_values($capabilities);

        return $this;
    }

    public function addCapabilities(string ...$capabilities): self
    {
        $this->addedCapabilities = [...$this->addedCapabilities, ...array_values($capabilities)];

        return $this;
    }

    /**
     * Declares a typed field, stored in the item's "fields" and checked on every save.
     *
     * @param FieldType|string $type       "string", "text", "int", "float", "bool", "enum", "list", "date", "datetime" or "json"
     * @param mixed            $default    Set on new items that don't provide a value
     * @param bool             $required   New items must have a value, and it can't be removed
     * @param list<string|int> $choices    Allowed values, for "enum" fields
     * @param int|float|null   $min        Smallest value, for "int" and "float" fields
     * @param int|float|null   $max        Largest value, for "int" and "float" fields
     * @param int|null         $maxLength  Most characters, for "string", "text" and "list" fields (per item). Indexed
     *                                     string fields default to 255 and allow at most 1000.
     * @param bool             $sortable   Listings can sort by it. The field gets a database index.
     * @param bool             $filterable Listings can filter by it. The field gets a database index.
     * @param string|null      $widget     Hint for admin interfaces, e.g. "textarea" or "color"
     * @param int|null         $order      Position in admin forms; defaults to declaration order
     */
    public function field(
        string $name,
        FieldType|string $type,
        mixed $default = null,
        bool $required = false,
        array $choices = [],
        int|float|null $min = null,
        int|float|null $max = null,
        ?int $maxLength = null,
        bool $sortable = false,
        bool $filterable = false,
        ?string $label = null,
        ?string $help = null,
        ?string $group = null,
        ?string $widget = null,
        ?int $order = null,
    ): self {
        $this->fields[] = ['name' => $name, 'type' => $type, 'default' => $default, 'required' => $required, 'choices' => $choices, 'min' => $min, 'max' => $max, 'maxLength' => $maxLength, 'sortable' => $sortable, 'filterable' => $filterable, 'label' => $label, 'help' => $help, 'group' => $group, 'widget' => $widget, 'order' => $order];

        return $this;
    }

    /**
     * Default URL pattern, built from static text and the placeholders {slug}, {path} (ancestors' slugs
     * and the item's, for hierarchical types), {id}, {year}, {month} and {day}. Sites can change it.
     *
     * Defaults to "/{type}/{slug}", or "/{type}/{path}" for hierarchical types.
     */
    public function permalink(string $pattern): self
    {
        $this->permalink = $pattern;

        return $this;
    }

    /**
     * Default order of listings: "published_at", "created_at", "updated_at", "title", "position", or a
     * sortable field as "fields.<name>".
     *
     * Defaults to "position" ascending for hierarchical types and "published_at" descending otherwise.
     *
     * @param 'asc'|'desc' $direction
     */
    public function sortBy(string $field, string $direction = 'asc'): self
    {
        $this->sortField = $field;
        $this->sortDirection = $direction;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $all = $container->getParameterBag()->all()[ContentTypeRegistry::PARAMETER] ?? [];
        $all = \is_array($all) ? $all : [];

        $type = $this->existing ? $this->modified($context, $all) : $this->registered($context, $all);
        $type = $this->withFields($context, $type);

        if (!$this->existing) {
            $this->validateSort($context, $type);
        }

        $all[$this->id] = $type->toArray();
        $container->setParameter(ContentTypeRegistry::PARAMETER, $all);
    }

    /**
     * @param array<mixed> $all
     */
    private function registered(ExtenderContext $context, array $all): ContentTypeDefinition
    {
        $where = \sprintf('Extend\ContentType("%s")', $this->id);

        if (1 !== preg_match(self::ID_PATTERN, $this->id)) {
            throw $context->error($where, 'Type IDs start with a lowercase letter, followed by up to 63 lowercase letters, digits or underscores, e.g. "product".');
        }

        $existing = $all[$this->id] ?? null;

        if (\is_array($existing)) {
            $owner = \is_string($existing['owner'] ?? null) ? $existing['owner'] : 'another extension';

            throw $context->error($where, \sprintf('The type is already registered by "%s". To add capabilities to it, use Extend\ContentType::existing("%s").', $owner, $this->id));
        }

        $capabilities = $this->validCapabilities($context, $where, [...$this->capabilities ?? [], ...$this->addedCapabilities]);
        $hierarchical = \in_array(Capability::HIERARCHICAL, $capabilities, true);
        $permalink = $this->permalink ?? \sprintf('/%s/{%s}', str_replace('_', '-', $this->id), $hierarchical ? 'path' : 'slug');
        $this->validatePermalink($context, $where, $permalink, $hierarchical);

        $sortField = $this->sortField ?? ($hierarchical ? 'position' : 'published_at');
        $sortDirection = null === $this->sortField && !$hierarchical ? 'desc' : $this->sortDirection;

        if ('asc' !== $sortDirection && 'desc' !== $sortDirection) {
            throw $context->error($where, 'The sort direction is "asc" or "desc".');
        }

        $label = $this->label ?? ucfirst(str_replace('_', ' ', $this->id));

        return new ContentTypeDefinition(
            id: $this->id,
            label: $label,
            pluralLabel: $this->pluralLabel ?? $label.'s',
            owner: $context->id,
            capabilities: $capabilities,
            permalink: $permalink,
            sortField: $sortField,
            sortDirection: $sortDirection,
        );
    }

    /**
     * @param array<mixed> $all
     */
    private function modified(ExtenderContext $context, array $all): ContentTypeDefinition
    {
        $where = \sprintf('Extend\ContentType::existing("%s")', $this->id);
        $existing = $all[$this->id] ?? null;

        if (!\is_array($existing)) {
            throw $context->error($where, 'No content type with this ID is registered yet. If another extension provides it, require that extension in composer.json, so it boots first.');
        }

        if (null !== $this->label || null !== $this->capabilities || null !== $this->permalink || null !== $this->sortField) {
            throw $context->error($where, 'Only capabilities and fields can be added to a type registered elsewhere. Sites change permalinks in settings.');
        }

        /** @var array<string, mixed> $existing */
        $type = ContentTypeDefinition::fromArray($existing);
        $capabilities = $this->validCapabilities($context, $where, [...$type->capabilities, ...$this->addedCapabilities]);

        if (\in_array(Capability::HIERARCHICAL, $capabilities, true) && !$type->has(Capability::HIERARCHICAL)) {
            throw $context->error($where, 'Only the extension that registers a type can make it hierarchical.');
        }

        return new ContentTypeDefinition(
            id: $type->id,
            label: $type->label,
            pluralLabel: $type->pluralLabel,
            owner: $type->owner,
            capabilities: $capabilities,
            permalink: $type->permalink,
            sortField: $type->sortField,
            sortDirection: $type->sortDirection,
            fields: $type->fields,
        );
    }

    private function validateSort(ExtenderContext $context, ContentTypeDefinition $type): void
    {
        $where = \sprintf('Extend\ContentType("%s")::sortBy()', $this->id);

        if (str_starts_with($type->sortField, 'fields.')) {
            $field = $type->field(substr($type->sortField, \strlen('fields.')));

            if (!$field instanceof FieldDefinition || !$field->sortable) {
                throw $context->error($where, \sprintf('Listings can only be sorted by fields declared with "sortable: true", which "%s" isn\'t.', $type->sortField));
            }
        } elseif (!\in_array($type->sortField, self::SORT_FIELDS, true)) {
            throw $context->error($where, \sprintf('Listings can\'t be sorted by "%s". Use one of: %s, or a sortable field as "fields.<name>".', $type->sortField, implode(', ', self::SORT_FIELDS)));
        }
    }

    private function withFields(ExtenderContext $context, ContentTypeDefinition $type): ContentTypeDefinition
    {
        $fields = $type->fields;
        $nextOrder = [] === $fields ? 0 : max(array_map(static fn (FieldDefinition $field): int => $field->order, $fields)) + 10;

        foreach ($this->fields as $declaration) {
            $field = $this->fieldDefinition($context, $declaration, $nextOrder);

            if (isset($fields[$field->name])) {
                throw $context->error(\sprintf('Extend\ContentType("%s")::field("%s")', $this->id, $field->name), \sprintf('The field is already declared by "%s".', $fields[$field->name]->owner));
            }

            $fields[$field->name] = $field;
            $nextOrder = max($nextOrder, $field->order) + 10;
        }

        uasort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => $a->order <=> $b->order);

        return new ContentTypeDefinition(
            id: $type->id,
            label: $type->label,
            pluralLabel: $type->pluralLabel,
            owner: $type->owner,
            capabilities: $type->capabilities,
            permalink: $type->permalink,
            sortField: $type->sortField,
            sortDirection: $type->sortDirection,
            fields: $fields,
        );
    }

    /**
     * @param array<string, mixed> $declaration As collected by {@see field()}
     */
    private function fieldDefinition(ExtenderContext $context, array $declaration, int $defaultOrder): FieldDefinition
    {
        /** @var array{name: string, type: FieldType|string, default: mixed, required: bool, choices: list<string|int>, min: int|float|null, max: int|float|null, maxLength: ?int, sortable: bool, filterable: bool, label: ?string, help: ?string, group: ?string, widget: ?string, order: ?int} $d */
        $d = $declaration;
        $where = \sprintf('Extend\ContentType("%s")::field("%s")', $this->id, $d['name']);
        $typeName = $d['type'] instanceof FieldType ? $d['type']->value : $d['type'];
        $type = FieldType::tryFrom($typeName) ?? throw $context->error($where, \sprintf('Unknown type "%s". Use one of: %s.', $typeName, implode(', ', array_map(static fn (FieldType $t): string => $t->value, FieldType::cases()))));

        $error = match (true) {
            1 !== preg_match(self::FIELD_PATTERN, $d['name']) => 'Field names start with a lowercase letter, followed by up to 63 lowercase letters, digits or underscores.',
            FieldType::Enum === $type && [] === $d['choices'] => 'Fields of type "enum" need a list of choices.',
            FieldType::Enum !== $type && [] !== $d['choices'] => 'Choices only apply to fields of type "enum".',
            \count($d['choices']) !== \count(array_unique($d['choices'], \SORT_REGULAR)) => 'The choices must be different from each other.',
            !$type->isNumeric() && (null !== $d['min'] || null !== $d['max']) => 'min and max only apply to "int" and "float" fields.',
            null !== $d['min'] && null !== $d['max'] && $d['min'] > $d['max'] => 'min can\'t be larger than max.',
            null !== $d['maxLength'] && !\in_array($type, [FieldType::String, FieldType::Text, FieldType::List], true) => 'maxLength only applies to "string", "text" and "list" fields.',
            null !== $d['maxLength'] && $d['maxLength'] < 1 => 'maxLength must be at least 1.',
            $d['sortable'] && !$type->canBeSorted() => \sprintf('Fields of type "%s" can\'t be sortable.', $type->value),
            $d['filterable'] && !$type->canBeFiltered() => \sprintf('Fields of type "%s" can\'t be filterable.', $type->value),
            ($d['sortable'] || $d['filterable']) && FieldType::String === $type && ($d['maxLength'] ?? 0) > self::MAX_INDEXED_LENGTH => \sprintf('Indexed string fields can have a maxLength of at most %d, so their index entries fit.', self::MAX_INDEXED_LENGTH),
            default => null,
        };

        if (null !== $error) {
            throw $context->error($where, $error);
        }

        $indexedString = ($d['sortable'] || $d['filterable']) && FieldType::String === $type;

        $field = new FieldDefinition(
            name: $d['name'],
            type: $type,
            owner: $context->id,
            required: $d['required'],
            choices: $d['choices'],
            min: $d['min'],
            max: $d['max'],
            maxLength: $d['maxLength'] ?? ($indexedString ? self::DEFAULT_INDEXED_LENGTH : null),
            sortable: $d['sortable'],
            filterable: $d['filterable'],
            label: $d['label'],
            help: $d['help'],
            group: $d['group'],
            widget: $d['widget'],
            order: $d['order'] ?? $defaultOrder,
        );

        if (null === $d['default']) {
            return $field;
        }

        $default = $field->normalize($d['default']);
        $defaultError = $field->validate($default);

        if (null !== $defaultError) {
            throw $context->error($where, \sprintf('The default value doesn\'t fit the field: %s', $defaultError));
        }

        return new FieldDefinition(
            name: $field->name,
            type: $field->type,
            owner: $field->owner,
            default: $default,
            required: $field->required,
            choices: $field->choices,
            min: $field->min,
            max: $field->max,
            maxLength: $field->maxLength,
            sortable: $field->sortable,
            filterable: $field->filterable,
            label: $field->label,
            help: $field->help,
            group: $field->group,
            widget: $field->widget,
            order: $field->order,
        );
    }

    /**
     * @param list<string> $capabilities
     *
     * @return list<string> Without duplicates
     */
    private function validCapabilities(ExtenderContext $context, string $where, array $capabilities): array
    {
        foreach ($capabilities as $capability) {
            if (1 !== preg_match(self::CAPABILITY_PATTERN, $capability)) {
                throw $context->error($where, \sprintf('"%s" isn\'t a valid capability: use lowercase letters, digits and underscores, e.g. "commentable".', $capability));
            }
        }

        return array_values(array_unique($capabilities));
    }

    private function validatePermalink(ExtenderContext $context, string $where, string $pattern, bool $hierarchical): void
    {
        if (1 !== preg_match(self::PERMALINK_PATTERN, $pattern)) {
            throw $context->error($where, \sprintf('The permalink "%s" must start with "/" and contain only lowercase letters, digits, "-", "_", ".", "~" and placeholders such as {slug}.', $pattern));
        }

        preg_match_all('/\{([a-z]+)\}/', $pattern, $matches);
        $placeholders = $matches[1];

        foreach ($placeholders as $placeholder) {
            if (!\in_array($placeholder, self::PLACEHOLDERS, true)) {
                throw $context->error($where, \sprintf('The permalink "%s" uses the unknown placeholder {%s}. Use: {%s}.', $pattern, $placeholder, implode('}, {', self::PLACEHOLDERS)));
            }
        }

        if (\count($placeholders) !== \count(array_unique($placeholders))) {
            throw $context->error($where, \sprintf('The permalink "%s" uses a placeholder more than once.', $pattern));
        }

        if ([] === array_intersect($placeholders, self::IDENTIFYING_PLACEHOLDERS)) {
            throw $context->error($where, \sprintf('The permalink "%s" needs {slug}, {path} or {id}, so each item gets its own URL.', $pattern));
        }

        if (\in_array('path', $placeholders, true) && !$hierarchical) {
            throw $context->error($where, \sprintf('The permalink "%s" uses {path}, which only hierarchical types have. Use {slug}.', $pattern));
        }
    }
}
