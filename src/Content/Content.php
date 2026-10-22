<?php

declare(strict_types=1);

namespace Quire\Content;

/**
 * A content item as stored: a post, a page, or an item of an extension's type.
 *
 * Instances are immutable snapshots. Change content through {@see ContentWriter}.
 *
 * @api
 */
final readonly class Content
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $attributes What a query added: selected values (e.g. a comment count) and loaded
     *                                         relations (e.g. "children"), by name. Read them as properties.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public string $locale,
        public ?string $translationGroupId,
        public string $title,
        public string $slug,
        /** The slug, below the parent's path for hierarchical types, e.g. "about/team". Permalinks use it as {path}. */
        public string $path,
        public ?string $parentId,
        public int $position,
        public ?string $excerpt,
        public string $body,
        public string $bodyFormat,
        public array $fields,
        public ?string $authorId,
        public int $version,
        public ?\DateTimeImmutable $publishedAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $deletedAt,
        public array $attributes = [],
    ) {
    }

    /**
     * A value the query added, e.g. `$page->attribute('children')` or `$post->attribute('comment_count')`.
     * Templates read them as properties: `{{ page.children }}`.
     *
     * @throws \LogicException when the query didn't select or load it
     */
    public function attribute(string $name): mixed
    {
        if (!\array_key_exists($name, $this->attributes)) {
            throw new \LogicException(\sprintf('"%1$s" isn\'t loaded for this %2$s. Load relations with ->with("%1$s") on the content query, and select values with ->select("%1$s", ...).', $name, $this->type));
        }

        return $this->attributes[$name];
    }

    /**
     * Lets templates read attributes as properties, e.g. `{{ post.comment_count }}`. In PHP, prefer
     * {@see attribute()}, which static analysis understands.
     *
     * @throws \LogicException when the query didn't select or load it
     */
    public function __get(string $name): mixed
    {
        return $this->attribute($name);
    }

    public function __isset(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }

    /**
     * @internal Used by the content query to add selected values and loaded relations
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return clone ($this, ['attributes' => [...$this->attributes, ...$attributes]]);
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return \array_key_exists($name, $this->fields) ? $this->fields[$name] : $default;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    /**
     * Whether visitors can see the item: published (or scheduled and due), and not in the trash.
     */
    public function isPublic(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isTrashed() || !\in_array($this->status, [Status::PUBLISHED, Status::SCHEDULED], true)) {
            return false;
        }

        return $this->publishedAt instanceof \DateTimeImmutable && $this->publishedAt <= ($now ?? new \DateTimeImmutable());
    }
}
