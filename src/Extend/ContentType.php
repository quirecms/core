<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;

/**
 * Registers a content type, or adds capabilities to one that another extension registered.
 *
 *     new Extend\ContentType('product')
 *         ->label('Product', 'Products')
 *         ->capabilities(Capability::REVISIONS, Capability::LISTABLE, Capability::SEARCHABLE)
 *         ->permalink('/products/{slug}')
 *         ->sortBy('title');
 *
 *     Extend\ContentType::existing('post')->addCapabilities('commentable');
 *
 * Read the registered types with {@see ContentTypeRegistry}.
 *
 * @api
 */
final class ContentType implements ExtenderInterface
{
    private const string ID_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    private const string CAPABILITY_PATTERN = '/^[a-z][a-z0-9_]*$/';

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

    public function __construct(
        private readonly string $id,
        private readonly bool $existing = false,
    ) {
    }

    /**
     * Changes a type registered by core or by another extension. Only capabilities can be added.
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
     * Default order of listings: "published_at", "created_at", "updated_at", "title" or "position".
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

        if (!\in_array($sortField, self::SORT_FIELDS, true)) {
            throw $context->error($where, \sprintf('Listings can\'t be sorted by "%s". Use one of: %s.', $sortField, implode(', ', self::SORT_FIELDS)));
        }

        if (!\in_array($sortDirection, ['asc', 'desc'], true)) {
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
            throw $context->error($where, 'Only capabilities can be added to a type registered elsewhere. Sites change permalinks in settings.');
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
