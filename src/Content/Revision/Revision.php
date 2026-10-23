<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * An immutable copy of a content item's text and fields, saved each time they change.
 *
 * @api
 */
final readonly class Revision
{
    /**
     * @param int                  $contentVersion The item's version this revision was saved as
     * @param array<string, mixed> $fields
     * @param string|null          $authorId       Who made the change
     * @param string|null          $approvedBy     Who approved it, for changes published after review
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public int $contentVersion,
        public string $title,
        public ?string $excerpt,
        public string $body,
        public string $bodyFormat,
        public array $fields,
        public ?string $authorId,
        public ?string $message,
        public \DateTimeImmutable $createdAt,
        public ?string $approvedBy = null,
    ) {
    }
}
