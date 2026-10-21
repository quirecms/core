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
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public string $locale,
        public ?string $translationGroupId,
        public string $title,
        public string $slug,
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
    ) {
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
