<?php

declare(strict_types=1);

namespace Quire\Content\Command;

/**
 * A request to change a content item. Only what you set changes:
 *
 *     $writer->update(new UpdateContent($id, expectedVersion: 3)->title('New title')->excerpt(null));
 *
 * The expected version is the version the editor started from. If someone saved the item in the
 * meantime, the update fails with a {@see \Quire\Content\Exception\ContentConflictException} instead of
 * silently overwriting their work.
 *
 * Changing the title keeps the slug, so published URLs don't change by accident. Set a new slug explicitly.
 *
 * Listeners of {@see \Quire\Content\Event\ContentUpdating} can add or change the changes before they're saved.
 *
 * @api
 */
final class UpdateContent
{
    public const array CHANGEABLE = ['title', 'slug', 'body', 'body_format', 'excerpt', 'fields', 'parent_id', 'position', 'locale', 'author_id', 'published_at'];

    /** @var array<string, mixed> */
    private array $changes = [];

    /** @var array<string, mixed> */
    private array $fieldChanges = [];

    public function __construct(
        public readonly string $id,
        public readonly int $expectedVersion,
        public ?string $revisionMessage = null,
        public ?string $actorId = null,
    ) {
    }

    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    public function slug(string $slug): self
    {
        return $this->set('slug', $slug);
    }

    public function body(string $body, ?string $format = null): self
    {
        $this->set('body', $body);

        return null === $format ? $this : $this->set('body_format', $format);
    }

    public function excerpt(?string $excerpt): self
    {
        return $this->set('excerpt', $excerpt);
    }

    /**
     * Replaces all fields.
     *
     * @param array<string, mixed> $fields
     */
    public function fields(array $fields): self
    {
        $this->fieldChanges = [];

        return $this->set('fields', $fields);
    }

    /**
     * Changes one field and keeps the others. Null removes the field.
     */
    public function field(string $name, mixed $value): self
    {
        $this->fieldChanges[$name] = $value;

        return $this;
    }

    public function parent(?string $parentId): self
    {
        return $this->set('parent_id', $parentId);
    }

    public function position(int $position): self
    {
        return $this->set('position', $position);
    }

    public function locale(string $locale): self
    {
        return $this->set('locale', $locale);
    }

    public function author(?string $authorId): self
    {
        return $this->set('author_id', $authorId);
    }

    /**
     * Changes the publication date of a published or scheduled item. To publish at a future date, use the
     * "schedule" transition.
     */
    public function publishedAt(\DateTimeImmutable $publishedAt): self
    {
        return $this->set('published_at', $publishedAt);
    }

    /**
     * @param value-of<self::CHANGEABLE> $property
     */
    public function has(string $property): bool
    {
        return \array_key_exists($property, $this->changes) || ('fields' === $property && [] !== $this->fieldChanges);
    }

    /**
     * @return array<string, mixed> The changed properties, keyed by column name (see {@see CHANGEABLE})
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, mixed> Single fields changed with {@see field()}
     */
    public function fieldChanges(): array
    {
        return $this->fieldChanges;
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes && [] === $this->fieldChanges;
    }

    private function set(string $property, mixed $value): self
    {
        $this->changes[$property] = $value;

        return $this;
    }
}
