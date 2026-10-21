<?php

declare(strict_types=1);

namespace Quire\Content\Command;

/**
 * A request to create a content item. New items are drafts; publish them with
 * {@see \Quire\Content\ContentWriter::transition()}.
 *
 * Listeners of {@see \Quire\Content\Event\ContentCreating} can change the properties before the item is saved.
 *
 * @api
 */
final class CreateContent
{
    /**
     * @param string|null          $slug          Generated from the title when null, and made unique among siblings
     * @param string|null          $locale        Defaults to the "site.locale" setting
     * @param array<string, mixed> $fields
     * @param string|null          $parentId      For hierarchical types
     * @param int|null             $position      Among siblings; defaults to after the last one
     * @param string|null          $translationOf ID of the item this one translates
     * @param string|null          $actorId       Who makes the change, recorded in the revision
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body = '',
        public string $bodyFormat = 'markdown',
        public ?string $slug = null,
        public ?string $locale = null,
        public ?string $excerpt = null,
        public array $fields = [],
        public ?string $parentId = null,
        public ?int $position = null,
        public ?string $authorId = null,
        public ?string $translationOf = null,
        public ?string $revisionMessage = null,
        public ?string $actorId = null,
    ) {
    }
}
