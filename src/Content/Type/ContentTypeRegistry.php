<?php

declare(strict_types=1);

namespace Quire\Content\Type;

use Quire\Content\Type\Exception\UnknownContentTypeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every content type registered by core and the enabled extensions.
 *
 * @api
 */
final readonly class ContentTypeRegistry
{
    public const string PARAMETER = 'quire.content.types';

    /** @var array<string, ContentTypeDefinition> */
    private array $types;

    /**
     * @param array<string, array<string, mixed>> $types Compiled by {@see \Quire\Extend\ContentType}, in registration order
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $types,
    ) {
        $this->types = array_map(ContentTypeDefinition::fromArray(...), $types);
    }

    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    /**
     * @throws UnknownContentTypeException
     */
    public function get(string $id): ContentTypeDefinition
    {
        return $this->types[$id] ?? throw UnknownContentTypeException::forId($id, array_keys($this->types));
    }

    /**
     * @return array<string, ContentTypeDefinition> Keyed by ID, in registration order (core first)
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * @return array<string, ContentTypeDefinition> The types with a capability, e.g. every "listable" type
     */
    public function withCapability(string $capability): array
    {
        return array_filter($this->types, static fn (ContentTypeDefinition $type): bool => $type->has($capability));
    }
}
