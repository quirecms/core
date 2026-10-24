<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Event\PendingRevisionDiscarded;
use Quire\Content\Event\PendingRevisionPublishFailed;
use Quire\Content\Event\PendingRevisionReviewed;
use Quire\Content\Event\PendingRevisionSaved;
use Quire\Content\Event\PendingRevisionScheduled;
use Quire\Content\Event\PendingRevisionSubmitted;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\PendingRevisionConflictException;
use Quire\Content\Internal\Fields;
use Quire\Content\Internal\Relations;
use Quire\Content\Revision\Internal\Values;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Formatter\Formatter;

/**
 * Creates, saves, reviews and discards pending revisions: changes to content that go live only when
 * they're published, with {@see \Quire\Content\ContentWriter::publishPendingRevision()}. Admin interfaces
 * use them to edit published items without publishing half-written changes, and for review.
 *
 * Like {@see \Quire\Content\ContentWriter}, each method runs in one transaction and dispatches its event
 * after the transaction commits.
 *
 * @api
 */
final readonly class PendingRevisionWriter
{
    public function __construct(
        private Connection $connection,
        private PendingRevisionRepository $pending,
        private ContentRepository $content,
        private RevisionRepository $revisions,
        private ContentTypeRegistry $types,
        private Relations $relations,
        private Formatter $formatter,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Starts changes to an item, from its current text and fields, or from those of one of its revisions
     * (to restore it after review).
     *
     * @param string|null $authorId     Who writes the changes
     * @param string|null $fromRevision ID of a revision of the item to start from
     *
     * @throws ContentNotFoundException
     * @throws InvalidContentException  when the item is in the trash, or the revision or author doesn't exist
     */
    public function create(string $contentId, ?string $authorId = null, ?string $message = null, ?string $fromRevision = null): PendingRevision
    {
        return $this->write(function () use ($contentId, $authorId, $message, $fromRevision): array {
            $content = $this->editable($contentId);
            $proposed = Snapshot::of($content);

            if (null !== $fromRevision) {
                $revision = $this->revisions->find($fromRevision);

                if (!$revision instanceof Revision || $revision->contentId !== $content->id) {
                    throw InvalidContentException::single('revision', \sprintf('The item has no revision "%s".', $fromRevision));
                }

                $type = $this->types->get($content->type);
                $fields = Fields::normalize($type, $this->relations->fieldsToRestore($type, $content->fields, $revision->fields));
                $proposed = new Snapshot($revision->title, $revision->excerpt, $revision->body, $revision->bodyFormat, $fields);
            }

            $id = $this->connection->fetchOne(
                'INSERT INTO quire.content_pending_revision (content_id, base_version, base, title, excerpt, body, body_format, fields, message, author_id)
                 VALUES (:content, :version, :base, :title, :excerpt, :body, :format, :fields, :message, :author)
                 RETURNING id',
                [
                    'content' => $content->id,
                    'version' => $content->version,
                    'base' => $this->json(Snapshot::of($content)->toArray()),
                    'title' => $proposed->title,
                    'excerpt' => $proposed->excerpt,
                    'body' => $proposed->body,
                    'format' => $proposed->bodyFormat,
                    'fields' => $this->json($proposed->fields),
                    'message' => $message,
                    'author' => $authorId,
                ],
            );

            $pending = $this->pending->get(\is_string($id) ? $id : throw new \LogicException('INSERT … RETURNING id returned no ID.'));

            return [$pending, new PendingRevisionSaved($pending, created: true)];
        });
    }

    /**
     * Saves changes to the text, fields or message, e.g. as the editor types. Saving changes to an approved
     * pending revision asks for a review again.
     *
     * @throws ContentNotFoundException
     * @throws PendingRevisionConflictException when it was saved since the expected version
     * @throws ContentConflictException         when rebasing onto a version that's no longer the item's
     * @throws InvalidContentException          for values that can't be saved even as work in progress
     */
    public function update(UpdatePendingRevision $command): PendingRevision
    {
        return $this->write(function () use ($command): array {
            $current = $this->lock($command->id, $command->expectedVersion);
            $content = $this->editable($current->contentId);

            if ($command->isEmpty()) {
                return [$current, null];
            }

            $values = $current->proposed->toArray() + ['message' => $current->message];
            $values = [...$values, ...$command->changes()];

            $this->throwIfInvalid($values);
            /** @var array<string, mixed> $fields */
            $fields = $values['fields'];

            foreach ($command->fieldChanges() as $name => $value) {
                $fields[$name] = $value;
            }

            $values['fields'] = Fields::normalize($this->types->get($content->type), $fields);
            $base = $current->base;
            $baseVersion = $current->baseVersion;

            if (null !== $command->rebaseOnto()) {
                if ($command->rebaseOnto() !== $content->version) {
                    throw new ContentConflictException($content->id, $command->rebaseOnto(), $content->version);
                }

                [$base, $baseVersion] = [Snapshot::of($content), $content->version];
            }

            if ($baseVersion === $current->baseVersion && Values::same($values, $current->proposed->toArray() + ['message' => $current->message])) {
                return [$current, null];
            }

            $this->connection->executeStatement(
                'UPDATE quire.content_pending_revision
                 SET title = :title, excerpt = :excerpt, body = :body, body_format = :format, fields = :fields, message = :message,
                     base = :base, base_version = :base_version, state = :state, version = version + 1, updated_at = statement_timestamp()
                 WHERE id = :id',
                [
                    'id' => $current->id,
                    'title' => $values['title'],
                    'excerpt' => $values['excerpt'],
                    'body' => $values['body'],
                    'format' => $values['body_format'],
                    'fields' => $this->json($values['fields']),
                    'message' => $values['message'],
                    'base' => $this->json($base->toArray()),
                    'base_version' => $baseVersion,
                    // An approval is for what the reviewer saw.
                    'state' => (ReviewState::Approved === $current->state ? ReviewState::InReview : $current->state)->value,
                ],
            );

            $pending = $this->pending->get($current->id);

            return [$pending, new PendingRevisionSaved($pending, created: false)];
        });
    }

    /**
     * Asks for a review: the pending revision is "in review" until a reviewer approves it or asks for changes.
     *
     * @throws ContentNotFoundException
     * @throws PendingRevisionConflictException
     */
    public function submit(string $id, int $expectedVersion, ?string $actorId = null): PendingRevision
    {
        return $this->write(function () use ($id, $expectedVersion, $actorId): array {
            $current = $this->lock($id, $expectedVersion);

            if (\in_array($current->state, [ReviewState::InReview, ReviewState::Approved], true)) {
                return [$current, null];
            }

            $this->connection->executeStatement('UPDATE quire.content_pending_revision SET state = ? WHERE id = ?', [ReviewState::InReview->value, $current->id]);
            $pending = $this->pending->get($current->id);

            return [$pending, new PendingRevisionSubmitted($pending, $actorId)];
        });
    }

    /**
     * Records a review of the pending revision as it is at the expected version. Approving or asking for
     * changes sets its state; comments don't. Authors can't approve their own changes.
     *
     * @throws ContentNotFoundException
     * @throws PendingRevisionConflictException when it was saved since the reviewer loaded it
     * @throws InvalidContentException          when the authors approves their own changes, or the reviewer doesn't exist
     */
    public function review(string $id, int $expectedVersion, Verdict $verdict, ?string $reviewerId = null, ?string $message = null): Review
    {
        return $this->write(function () use ($id, $expectedVersion, $verdict, $reviewerId, $message): array {
            $current = $this->lock($id, $expectedVersion);

            if (Verdict::Approve === $verdict && null !== $reviewerId && $reviewerId === $current->authorId) {
                throw InvalidContentException::single('reviewer_id', 'Authors can\'t approve their own changes. Ask someone else to review them.');
            }

            if (Verdict::Comment === $verdict && null === $message) {
                throw InvalidContentException::single('message', 'A comment needs a message.');
            }

            $reviewId = $this->connection->fetchOne(
                'INSERT INTO quire.content_revision_review (pending_revision_id, revision_version, verdict, reviewer_id, message) VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$current->id, $current->version, $verdict->value, $reviewerId, $message],
            );

            $state = match ($verdict) {
                Verdict::Approve => ReviewState::Approved,
                Verdict::RequestChanges => ReviewState::ChangesRequested,
                Verdict::Comment => $current->state,
            };

            if ($state !== $current->state) {
                $this->connection->executeStatement('UPDATE quire.content_pending_revision SET state = ? WHERE id = ?', [$state->value, $current->id]);
            }

            $pending = $this->pending->get($current->id);
            $review = array_find($this->pending->reviews($pending->id), static fn (Review $review): bool => $review->id === $reviewId) ?? throw new \LogicException('The review just saved is missing.');

            return [$review, new PendingRevisionReviewed($pending, $review)];
        });
    }

    /**
     * Sets when the changes are published, by the task quire.publish-due, with the same merge, conflict and
     * approval rules as publishing them by hand; null clears it. A time in the past publishes them on the
     * task's next run. A problem of an earlier time is forgotten.
     *
     * @throws ContentNotFoundException
     * @throws PendingRevisionConflictException when it was saved since the expected version
     */
    public function schedule(string $id, int $expectedVersion, ?\DateTimeImmutable $at, ?string $actorId = null): PendingRevision
    {
        return $this->write(function () use ($id, $expectedVersion, $at, $actorId): array {
            $current = $this->lock($id, $expectedVersion);
            $this->connection->executeStatement(
                'UPDATE quire.content_pending_revision SET publish_at = ?, publish_problem = NULL, scheduled_by = ? WHERE id = ?',
                [$at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'), $at instanceof \DateTimeImmutable ? $actorId : null, $current->id],
            );
            $pending = $this->pending->get($current->id);

            return [$pending, new PendingRevisionScheduled($pending, $actorId)];
        });
    }

    /**
     * Records why the changes couldn't be published at their time, and clears it.
     *
     * @internal Used by {@see \Quire\Content\ContentWriter::publishDue()}
     */
    public function failScheduled(string $id, string $reason): void
    {
        $this->write(function () use ($id, $reason): array {
            $this->connection->executeStatement('UPDATE quire.content_pending_revision SET publish_at = NULL, publish_problem = ? WHERE id = ?', [$reason, $id]);
            $pending = $this->pending->find($id);

            return [null, $pending instanceof PendingRevision ? new PendingRevisionPublishFailed($pending, $reason) : null];
        });
    }

    /**
     * Deletes the pending revision without publishing it, with its reviews.
     *
     * @throws ContentNotFoundException
     */
    public function discard(string $id, ?string $actorId = null): PendingRevision
    {
        return $this->write(function () use ($id, $actorId): array {
            $current = $this->pending->lock($id);
            $this->connection->executeStatement('DELETE FROM quire.content_pending_revision WHERE id = ?', [$current->id]);

            return [$current, new PendingRevisionDiscarded($current, $actorId)];
        });
    }

    /**
     * Runs an operation in a transaction and dispatches its event once it committed.
     *
     * @template T
     *
     * @param \Closure(): array{T, ?object} $operation The result, and the event to dispatch
     *
     * @return T
     */
    private function write(\Closure $operation): mixed
    {
        try {
            [$result, $event] = $this->connection->transactional(static fn (): array => $operation());
        } catch (ForeignKeyConstraintViolationException $e) {
            throw match (true) {
                str_contains($e->getMessage(), 'content_pending_revision_author_fk') => InvalidContentException::single('author_id', 'No user has this ID.'),
                str_contains($e->getMessage(), 'content_revision_review_reviewer_fk') => InvalidContentException::single('reviewer_id', 'No user has this ID.'),
                default => $e,
            };
        }

        if (null !== $event) {
            $this->dispatcher->dispatch($event);
        }

        return $result;
    }

    private function lock(string $id, int $expectedVersion): PendingRevision
    {
        $current = $this->pending->lock($id);

        if ($current->version !== $expectedVersion) {
            throw new PendingRevisionConflictException($current->id, $expectedVersion, $current->version);
        }

        return $current;
    }

    private function editable(string $contentId): Content
    {
        $content = $this->content->get($contentId);

        if ($content->isTrashed()) {
            throw InvalidContentException::single('deleted_at', 'The item is in the trash. Restore it before changing it.');
        }

        return $content;
    }

    /**
     * Checks what even work in progress needs: values of the right kinds, and a format there is.
     *
     * @param array<string, mixed> $values
     */
    private function throwIfInvalid(array $values): void
    {
        $formats = $this->formatter->profile('content')->formats;
        $violations = [];

        if (!\is_array($values['fields']) || ([] !== $values['fields'] && array_is_list($values['fields']))) {
            $violations['fields'] = 'Fields must be an object of names and values.';
        }

        if (!\in_array($values['body_format'], $formats, true)) {
            $violations['body_format'] = \sprintf('Unknown format. Use one of: %s.', implode(', ', $formats));
        }

        if ([] !== $violations) {
            throw new InvalidContentException($violations);
        }
    }

    private function json(mixed $value): string
    {
        // An empty PHP array is a list for json_encode(); fields are always an object.
        return [] === $value ? '{}' : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
