<?php

declare(strict_types=1);

namespace Quire\Content\Command;

/**
 * A change to a pending revision, e.g. an autosave. Only what you set changes:
 *
 *     $pending = $writer->update(new UpdatePendingRevision($pending->id, $pending->version)->body($markdown));
 *
 * Pending revisions accept work in progress: an empty title or an invalid field value is saved, and
 * reported when the changes are published.
 *
 * @api
 */
final class UpdatePendingRevision
{
    /** @var array<string, mixed> */
    private array $changes = [];

    /** @var array<string, mixed> */
    private array $fieldChanges = [];

    private ?int $rebaseOnto = null;

    /**
     * @param int $expectedVersion The pending revision's version the editor started from
     */
    public function __construct(
        public readonly string $id,
        public readonly int $expectedVersion,
    ) {
    }

    public function title(string $title): self
    {
        return $this->set('title', $title);
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

    /**
     * What the change is about, e.g. "Fix the opening hours". It becomes the message of the revision
     * saved on publishing.
     */
    public function message(?string $message): self
    {
        return $this->set('message', $message);
    }

    /**
     * Starts the changes again from the item's current version, keeping the text and fields set here.
     * After a {@see \Quire\Content\Exception\MergeConflictException}, set the resolved values and rebase
     * onto the version they were resolved against; if the item changed again since, saving fails with
     * a {@see \Quire\Content\Exception\ContentConflictException}.
     */
    public function rebase(int $contentVersion): self
    {
        $this->rebaseOnto = $contentVersion;

        return $this;
    }

    /**
     * @return array<string, mixed> Changed properties, keyed by column: title, body, body_format, excerpt, fields, message
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

    public function rebaseOnto(): ?int
    {
        return $this->rebaseOnto;
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes && [] === $this->fieldChanges && null === $this->rebaseOnto;
    }

    private function set(string $property, mixed $value): self
    {
        $this->changes[$property] = $value;

        return $this;
    }
}
