<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * Where a comment on changes points: text of a property, at a line, as it was when the comment was
 * written. Later versions find it again by its text: see {@see CommentThread::$line}.
 *
 * @api
 */
final readonly class CommentAnchor
{
    /**
     * @param string   $property "body", "title", "excerpt" or "fields.<name>"
     * @param string   $quote    The text it's about, on one line, e.g. "Northside United"
     * @param int      $line     For the body: the line, from 1, in the version the comment was written on
     * @param int|null $version  That version of the pending revision; set when the comment is saved
     */
    public function __construct(
        public string $property,
        public string $quote,
        public int $line = 1,
        public ?int $version = null,
    ) {
    }
}
