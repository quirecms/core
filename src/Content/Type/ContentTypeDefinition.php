<?php

declare(strict_types=1);

namespace Quire\Content\Type;

/**
 * A registered kind of content, such as "post", "page" or an extension's "product".
 *
 * @api
 */
final readonly class ContentTypeDefinition
{
    /**
     * @param string       $owner         ID of the extension that registered it, or "core"
     * @param list<string> $capabilities  See {@see Capability}; extensions can add their own flags
     * @param string       $permalink     Default URL pattern, e.g. "/blog/{slug}"
     * @param string       $sortField     Default sort for listings: a column such as "published_at"
     * @param 'asc'|'desc' $sortDirection
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $pluralLabel,
        public string $owner,
        public array $capabilities,
        public string $permalink,
        public string $sortField,
        public string $sortDirection,
    ) {
    }

    public function has(string $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'plural_label' => $this->pluralLabel,
            'owner' => $this->owner,
            'capabilities' => $this->capabilities,
            'permalink' => $this->permalink,
            'sort_field' => $this->sortField,
            'sort_direction' => $this->sortDirection,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{id: string, label: string, plural_label: string, owner: string, capabilities: list<string>, permalink: string, sort_field: string, sort_direction: 'asc'|'desc'} $type */
        $type = $data;

        return new self(
            id: $type['id'],
            label: $type['label'],
            pluralLabel: $type['plural_label'],
            owner: $type['owner'],
            capabilities: $type['capabilities'],
            permalink: $type['permalink'],
            sortField: $type['sort_field'],
            sortDirection: $type['sort_direction'],
        );
    }
}
