<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Quire\Content\Content;

/**
 * The part of a content item that revisions keep: its text and fields. Diffs and merges compare these.
 *
 * @api
 */
final readonly class Snapshot
{
    /** The properties, as named in diffs, merges and violations ("fields.<name>" for each field) */
    public const array PROPERTIES = ['title', 'excerpt', 'body', 'body_format', 'fields'];

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public string $title,
        public ?string $excerpt,
        public string $body,
        public string $bodyFormat,
        public array $fields,
    ) {
    }

    public static function of(Content|Revision|PendingRevision $source): self
    {
        if ($source instanceof PendingRevision) {
            return $source->proposed;
        }

        return new self($source->title, $source->excerpt, $source->body, $source->bodyFormat, $source->fields);
    }

    /**
     * @return array{title: string, excerpt: ?string, body: string, body_format: string, fields: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'excerpt' => $this->excerpt, 'body' => $this->body, 'body_format' => $this->bodyFormat, 'fields' => $this->fields];
    }

    /**
     * @param array<mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{title: string, excerpt: ?string, body: string, body_format: string, fields: array<string, mixed>} $snapshot */
        $snapshot = $data;

        return new self($snapshot['title'], $snapshot['excerpt'], $snapshot['body'], $snapshot['body_format'], $snapshot['fields']);
    }
}
