<?php

declare(strict_types=1);

namespace Quire\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Event\CancellableEvent;
use Quire\Content\Event\ContentCreated;
use Quire\Content\Event\ContentCreating;
use Quire\Content\Event\ContentDeleted;
use Quire\Content\Event\ContentDeleting;
use Quire\Content\Event\ContentPublished;
use Quire\Content\Event\ContentPublishing;
use Quire\Content\Event\ContentPurged;
use Quire\Content\Event\ContentPurging;
use Quire\Content\Event\ContentRestored;
use Quire\Content\Event\ContentStatusChanged;
use Quire\Content\Event\ContentUnpublished;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Event\ContentUpdating;
use Quire\Content\Event\PendingRevisionPublished;
use Quire\Content\Event\PendingRevisionPublishFailed;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Exception\ContentOperationCancelledException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\MergeConflictException;
use Quire\Content\Exception\PendingRevisionConflictException;
use Quire\Content\Exception\TransitionNotAllowedException;
use Quire\Content\Internal\Fields;
use Quire\Content\Internal\Locales;
use Quire\Content\Internal\Relations;
use Quire\Content\Internal\Row;
use Quire\Content\Internal\Slugs;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Revision\Internal\Values;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\Review;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Revision\RevisionRepository;
use Quire\Content\Revision\Verdict;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Content\Workflow\ContentWorkflow;
use Quire\Formatter\Formatter;
use Quire\Routing\RouteCollisions;
use Quire\Settings\Settings;

/**
 * Creates and changes content. Admin interfaces, the command line and APIs all write through this
 * service, so the same rules and events apply everywhere.
 *
 * Each method runs in one database transaction. "-ing" events (e.g. {@see ContentUpdating}) are dispatched
 * inside it, before the change, and can cancel it. "-ed" events (e.g. {@see ContentUpdated}) are dispatched
 * after the transaction commits, so their listeners never act on a change that was rolled back.
 *
 * @api
 */
final readonly class ContentWriter
{
    private const int MAX_TITLE_LENGTH = 1000;

    /** Whether pending revisions must be approved before they're published. */
    public const string APPROVAL_SETTING = 'quire.content.changes_need_approval';

    /** Changes to these are kept in revisions. */
    private const array REVISIONED = ['title', 'excerpt', 'body', 'body_format', 'fields'];

    public function __construct(
        private Connection $connection,
        private ContentRepository $repository,
        private RevisionRepository $revisions,
        private ContentTypeRegistry $types,
        private ContentWorkflow $workflow,
        private Settings $settings,
        private Slugs $slugs,
        private Formatter $formatter,
        private EventDispatcherInterface $dispatcher,
        private Permalinks $permalinks,
        private RouteCollisions $routes,
        private Relations $relations,
        private PendingRevisionRepository $pendingRevisions,
        private PendingRevisionWriter $pendingRevisionWriter,
        private ContentChecker $checker,
    ) {
    }

    /**
     * Creates a draft.
     *
     * @throws InvalidContentException
     * @throws ContentOperationCancelledException
     */
    public function create(CreateContent $command): Content
    {
        return $this->write(function (\ArrayObject $events) use ($command): Content {
            $this->dispatchCancellable(new ContentCreating($command));

            if (!$this->types->has($command->type)) {
                throw InvalidContentException::single('type', $this->types->has(strtolower($command->type)) ? 'Type IDs are lowercase.' : \sprintf('No content type "%s" is registered.', $command->type));
            }

            $type = $this->types->get($command->type);
            $locale = $command->locale ?? $this->settings->getString('site.locale');
            $violations = $this->validate($type, [
                'title' => $command->title,
                'locale' => $locale,
                'body_format' => $command->bodyFormat,
                'excerpt' => $command->excerpt,
                'fields' => $command->fields,
                'author_id' => $command->authorId,
            ]);

            $fields = [];

            if (!isset($violations['fields'])) {
                $fields = Fields::withDefaults($type, Fields::normalize($type, $command->fields));
                $violations += Fields::violations($type, null, $fields);
                $violations += $this->relations->violations($type, null, null, $fields);
            }

            if (null !== $command->slug && !Slugs::isValid($command->slug)) {
                $violations['slug'] = $this->invalidSlugMessage();
            }

            if (null !== $command->parentId) {
                $violations += $this->parentViolations($type, $command->parentId, $locale, null);
            }

            $translationGroup = null;

            if (null !== $command->translationOf) {
                [$translationGroup, $translationViolation] = $this->translationGroup($command->translationOf, $type, $locale);
                $violations += $translationViolation;
            }

            $this->throwIfAny($violations);

            $this->slugs->lockSiblings($type->id, $command->parentId, $locale);
            $slug = $this->claimSlug($command->slug, $command->title, $type->id, $command->parentId, $locale, null);
            $position = $command->position ?? ($type->has(Capability::HIERARCHICAL) ? $this->nextPosition($type->id, $command->parentId, $locale) : 0);

            $id = $this->connection->fetchOne(
                'INSERT INTO quire.content (type, status, locale, translation_group_id, title, slug, parent_id, position, excerpt, body, body_format, fields, author_id, search_config)
                 VALUES (:type, :status, :locale, :group, :title, :slug, :parent, :position, :excerpt, :body, :format, :fields, :author, CAST(:search AS regconfig))
                 RETURNING id',
                [
                    'type' => $type->id,
                    'status' => Status::DRAFT,
                    'locale' => $locale,
                    'group' => $translationGroup,
                    'title' => trim($command->title),
                    'slug' => $slug,
                    'parent' => $command->parentId,
                    'position' => $position,
                    'excerpt' => $command->excerpt,
                    'body' => $command->body,
                    'format' => $command->bodyFormat,
                    'fields' => $this->json($fields),
                    'author' => $command->authorId,
                    'search' => Locales::searchConfiguration($locale),
                ],
            );

            $id = \is_string($id) ? $id : throw new \LogicException('INSERT … RETURNING id returned no ID.');
            $this->relations->sync($id, $type, $fields, null);
            $content = $this->repository->get($id);

            if ($type->has(Capability::REVISIONS)) {
                $this->saveRevision($content, $command->revisionMessage, $command->actorId);
            }

            $this->assertReachable($content);
            $events->append(new ContentCreated($content));

            return $content;
        });
    }

    /**
     * Changes an item's text, fields, slug or place in the tree. Status changes go through {@see transition()}.
     *
     * @throws ContentNotFoundException
     * @throws ContentConflictException           when the item changed since the expected version
     * @throws InvalidContentException
     * @throws ContentOperationCancelledException
     */
    public function update(UpdateContent $command): Content
    {
        return $this->write(fn (\ArrayObject $events): Content => $this->applyUpdate($events, $command));
    }

    /**
     * Publishes a pending revision: merges its changes into the item and saves a revision. Changes
     * published since it was started are kept; if they changed the same lines or fields, publishing fails
     * with a {@see MergeConflictException} and nothing changes. The pending revision is deleted.
     *
     * When the site requires approval ("quire.content.changes_need_approval"), it must be approved.
     *
     * @param int         $expectedVersion The pending revision's version the publisher saw
     * @param string|null $actorId         Who publishes it. The revision is credited to the changes' author.
     *
     * @throws ContentNotFoundException
     * @throws PendingRevisionConflictException   when it was saved since the expected version
     * @throws MergeConflictException
     * @throws InvalidContentException            when the merged item breaks a rule (e.g. an empty title), or it needs approval
     * @throws ContentOperationCancelledException
     */
    public function publishPendingRevision(string $id, int $expectedVersion, ?string $actorId = null): Content
    {
        return $this->write(function (\ArrayObject $events) use ($id, $expectedVersion, $actorId): Content {
            $pending = $this->pendingRevisions->lock($id);

            if ($pending->version !== $expectedVersion) {
                throw new PendingRevisionConflictException($pending->id, $expectedVersion, $pending->version);
            }

            if (ReviewState::Approved !== $pending->state && $this->settings->getBool(self::APPROVAL_SETTING)) {
                throw InvalidContentException::single('state', 'The changes need to be approved before they\'re published.');
            }

            $current = $this->repository->lock($pending->contentId);

            if ($current->isTrashed()) {
                throw InvalidContentException::single('deleted_at', 'The item is in the trash. Restore it before changing it.');
            }

            $merge = $this->pendingRevisions->merge($pending, $current);

            if (!$merge->isClean()) {
                throw new MergeConflictException($pending->id, $merge->conflicts, $merge);
            }

            $approval = ReviewState::Approved === $pending->state
                ? array_find(array_reverse($this->pendingRevisions->reviews($pending->id)), static fn (Review $review): bool => Verdict::Approve === $review->verdict)
                : null;
            $merged = $merge->result;
            $after = $this->applyUpdate($events, new UpdateContent($current->id, $current->version, revisionMessage: $pending->message, actorId: $pending->authorId)
                ->title($merged->title)
                ->body($merged->body, $merged->bodyFormat)
                ->excerpt($merged->excerpt)
                ->fields($merged->fields), $approval?->reviewerId, $pending);

            $this->connection->executeStatement('DELETE FROM quire.content_pending_revision WHERE id = ?', [$pending->id]);
            $events->append(new PendingRevisionPublished($pending, $after, $actorId));

            return $after;
        });
    }

    /**
     * Moves an item to another status, e.g. "publish", "schedule", "unpublish", "archive", or a transition
     * an extension added.
     *
     * @param \DateTimeImmutable|null $at For "publish", the publication date (default: now, or the original
     *                                    date for archived items). For "schedule", the future date to publish at.
     *
     * @throws ContentNotFoundException
     * @throws ContentConflictException
     * @throws TransitionNotAllowedException
     * @throws InvalidContentException
     * @throws ContentOperationCancelledException
     */
    public function transition(string $id, int $expectedVersion, string $transition, ?\DateTimeImmutable $at = null): Content
    {
        return $this->write(function (\ArrayObject $events) use ($id, $expectedVersion, $transition, $at): Content {
            $current = $this->lockEditable($id, $expectedVersion);
            $target = $this->workflow->target($current, $transition);
            $blockers = $this->workflow->blockers($current, $transition);

            if (null === $target || [] !== $blockers) {
                throw new TransitionNotAllowedException($transition, $current->status, $blockers, $this->workflow->enabledTransitions($current));
            }

            $type = $this->types->get($current->type);
            $now = new \DateTimeImmutable();
            $publishedAt = $current->publishedAt;

            if (Status::SCHEDULED === $target) {
                if (!$type->has(Capability::SCHEDULING)) {
                    throw InvalidContentException::single('published_at', \sprintf('%s can\'t be scheduled.', $type->pluralLabel));
                }

                if (!$at instanceof \DateTimeImmutable || $at <= $now) {
                    throw InvalidContentException::single('published_at', 'Scheduling needs a publication date in the future.');
                }

                $publishedAt = $at;
            } elseif (Status::PUBLISHED === $target) {
                if ($at instanceof \DateTimeImmutable && $at > $now) {
                    throw InvalidContentException::single('published_at', 'To publish at a future date, use the "schedule" transition.');
                }

                $publishedAt = $at ?? (Status::ARCHIVED === $current->status ? $current->publishedAt : null) ?? $now;
                $this->dispatchCancellable(new ContentPublishing($current, $transition));
            } elseif ($at instanceof \DateTimeImmutable) {
                throw InvalidContentException::single('published_at', 'Only publishing and scheduling take a date.');
            }

            $status = $this->workflow->apply($current, $transition);
            $after = $this->saveChanges($current, ['status' => $status, 'published_at' => $publishedAt]);

            // Publishing passes the content checks; a scheduled item that goes live passed them when it was scheduled.
            if (\in_array($after->status, [Status::PUBLISHED, Status::SCHEDULED], true) && Status::SCHEDULED !== $current->status) {
                $this->assertPassesChecks($after);
            }
            // The publication date can be part of the URL.
            $this->assertReachable($after);

            $events->append(new ContentStatusChanged($current, $after, $transition));

            if (Status::PUBLISHED === $after->status) {
                $events->append(new ContentPublished($after));
            } elseif ($current->isPublic($now) && !$after->isPublic($now)) {
                $events->append(new ContentUnpublished($current, $after));
            }

            return $after;
        });
    }

    /**
     * Publishes scheduled items whose date has passed, so {@see ContentPublished} listeners run for them
     * (visitors already see them: visibility is evaluated when content is queried), then the pending
     * revisions whose time has come (CNT-035), as {@see publishPendingRevision()} does. A pending revision
     * that can't be published, because of a conflict, a missing approval or a rule its result breaks,
     * stays pending without a time, with the reason (see {@see PendingRevisionPublishFailed}).
     *
     * @return list<Content> The items published, or whose changes were
     *
     * @phpstan-impure
     */
    public function publishDue(int $limit = 100): array
    {
        return [...$this->publishDueItems($limit), ...$this->publishDueChanges($limit)];
    }

    /**
     * @return list<Content>
     */
    private function publishDueChanges(int $limit): array
    {
        $due = $this->connection->fetchAllAssociative(
            'SELECT id, version, scheduled_by FROM quire.content_pending_revision WHERE publish_at <= now() ORDER BY publish_at LIMIT ?',
            [$limit],
        );

        $published = [];

        foreach ($due as $values) {
            $row = new Row($values);
            $id = $row->string('id');

            try {
                $published[] = $this->publishPendingRevision($id, $row->int('version'), $row->nullableString('scheduled_by'));
            } catch (PendingRevisionConflictException|ContentNotFoundException) {
                // Saved or deleted in the meantime: it's considered again on the next run if it's still due.
            } catch (MergeConflictException $e) {
                $this->pendingRevisionWriter->failScheduled($id, \sprintf('Changes published since conflict with these: %s. Resolve them, then publish or schedule the changes again.', implode(', ', array_keys($e->conflicts))));
            } catch (InvalidContentException $e) {
                $this->pendingRevisionWriter->failScheduled($id, implode(' ', $e->violations));
            } catch (ContentOperationCancelledException $e) {
                $this->pendingRevisionWriter->failScheduled($id, $e->getMessage());
            }
        }

        return $published;
    }

    /**
     * @return list<Content>
     */
    private function publishDueItems(int $limit): array
    {
        $due = $this->connection->fetchAllAssociative(
            'SELECT id, version, published_at FROM quire.content
             WHERE status = ? AND published_at <= now() AND deleted_at IS NULL
             ORDER BY published_at LIMIT ?',
            [Status::SCHEDULED, $limit],
        );

        $published = [];

        foreach ($due as $values) {
            $row = new Row($values);

            try {
                $published[] = $this->transition($row->string('id'), $row->int('version'), Status::PUBLISH, $row->date('published_at'));
            } catch (ContentConflictException|ContentNotFoundException|TransitionNotAllowedException|ContentOperationCancelledException|InvalidContentException) {
                // Changed, deleted or blocked in the meantime: it's considered again on the next run if it's still due.
            }
        }

        return $published;
    }

    /**
     * @throws InvalidContentException when a content check finds an error, e.g. an image without a description
     */
    private function assertPassesChecks(Content $item): void
    {
        $report = $this->checker->check($item);

        if ($report->hasErrors()) {
            throw new InvalidContentException($report->violations());
        }
    }

    /**
     * Moves an item to the trash. Visitors no longer see it, and its slug becomes free.
     *
     * @throws ContentNotFoundException
     * @throws ContentConflictException
     * @throws InvalidContentException            when it has child items that aren't in the trash
     * @throws ContentOperationCancelledException
     */
    public function trash(string $id, int $expectedVersion): Content
    {
        return $this->write(function (\ArrayObject $events) use ($id, $expectedVersion): Content {
            $current = $this->lockEditable($id, $expectedVersion);

            if ($this->hasChildren($current->id, includeTrashed: false)) {
                throw InvalidContentException::single('children', 'The item has child items. Move them or move them to the trash first.');
            }

            $this->dispatchCancellable(new ContentDeleting($current));
            $after = $this->saveChanges($current, ['deleted_at' => new \DateTimeImmutable()]);
            $events->append(new ContentDeleted($after));

            return $after;
        });
    }

    /**
     * Restores an item from the trash. If another item took its slug meanwhile, it gets a numbered one.
     *
     * @throws ContentNotFoundException
     * @throws InvalidContentException  when it isn't in the trash, or its parent is
     */
    public function restore(string $id): Content
    {
        return $this->write(function (\ArrayObject $events) use ($id): Content {
            $current = $this->repository->lock($id);

            if (!$current->isTrashed()) {
                throw InvalidContentException::single('deleted_at', 'The item isn\'t in the trash.');
            }

            if (null !== $current->parentId && $this->repository->get($current->parentId)->isTrashed()) {
                throw InvalidContentException::single('parent_id', 'The item\'s parent is in the trash. Restore the parent first.');
            }

            $this->slugs->lockSiblings($current->type, $current->parentId, $current->locale);
            $slug = $this->slugs->unique($current->slug, $current->type, $current->parentId, $current->locale, $current->id);

            $after = $this->saveChanges($current, ['deleted_at' => null, 'slug' => $slug]);
            $events->append(new ContentRestored($after));

            return $after;
        });
    }

    /**
     * Deletes an item in the trash permanently, with its revisions and the extension data that references
     * it with cascading foreign keys.
     *
     * @throws ContentNotFoundException
     * @throws InvalidContentException            when it isn't in the trash, or still has child items
     * @throws ContentOperationCancelledException
     */
    public function purge(string $id): Content
    {
        return $this->write(function (\ArrayObject $events) use ($id): Content {
            $current = $this->repository->lock($id);

            if (!$current->isTrashed()) {
                throw InvalidContentException::single('deleted_at', 'Only items in the trash can be deleted permanently. Move it to the trash first.');
            }

            if ($this->hasChildren($current->id, includeTrashed: true)) {
                throw InvalidContentException::single('children', 'The item still has child items, some of them maybe in the trash. Move or delete them first.');
            }

            $this->dispatchCancellable(new ContentPurging($current));
            $this->relations->detach($current->id);
            $this->connection->executeStatement('DELETE FROM quire.content WHERE id = ?', [$current->id]);
            $events->append(new ContentPurged($current));

            return $current;
        });
    }

    /**
     * Brings back the text and fields of an earlier revision, as a new revision: history is never rewritten.
     *
     * @throws ContentNotFoundException
     * @throws ContentConflictException
     * @throws InvalidContentException  when the revision belongs to another item
     */
    public function restoreRevision(string $contentId, string $revisionId, int $expectedVersion, ?string $actorId = null): Content
    {
        $revision = $this->revisions->find($revisionId);

        if (!$revision instanceof Revision\Revision || $revision->contentId !== $contentId) {
            throw InvalidContentException::single('revision', \sprintf('The item has no revision "%s".', $revisionId));
        }

        $current = $this->repository->get($contentId);
        $fields = $this->relations->fieldsToRestore($this->types->get($current->type), $current->fields, $revision->fields);

        return $this->update(new UpdateContent(
            $contentId,
            $expectedVersion,
            revisionMessage: \sprintf('Restored the revision of %s', $revision->createdAt->format('Y-m-d H:i T')),
            actorId: $actorId,
        )
            ->title($revision->title)
            ->body($revision->body, $revision->bodyFormat)
            ->excerpt($revision->excerpt)
            ->fields($fields));
    }

    /**
     * @param \ArrayObject<int, object> $events
     * @param string|null               $approvedBy Who approved the changes, for the revision
     */
    private function applyUpdate(\ArrayObject $events, UpdateContent $command, ?string $approvedBy = null, ?PendingRevision $published = null): Content
    {
        $current = $this->lockEditable($command->id, $command->expectedVersion);
        $this->dispatchCancellable(new ContentUpdating($current, $command));

        if ($command->isEmpty()) {
            return $current;
        }

        $type = $this->types->get($current->type);
        $values = $this->changedValues($current, $command, $type);
        $violations = $this->validate($type, $values);

        if (\array_key_exists('fields', $values) && !isset($violations['fields']) && \is_array($values['fields'])) {
            /** @var array<string, mixed> $newFields */
            $newFields = $values['fields'];
            $violations += Fields::violations($type, $current->fields, $newFields);
            $violations += $this->relations->violations($type, $current->id, $current->fields, $newFields);
        }

        if (\array_key_exists('slug', $values) && !Slugs::isValid($this->string($values, 'slug'))) {
            $violations['slug'] = $this->invalidSlugMessage();
        }

        $parentId = \array_key_exists('parent_id', $values) ? $this->nullableString($values, 'parent_id') : $current->parentId;
        $locale = \array_key_exists('locale', $values) ? $this->string($values, 'locale') : $current->locale;

        if (\array_key_exists('parent_id', $values) && null !== $parentId) {
            $violations += $this->parentViolations($type, $parentId, $locale, $current->id);
        }

        if (\array_key_exists('locale', $values) && $this->hasChildren($current->id, includeTrashed: true)) {
            $violations['locale'] = 'Items with child items can\'t change their language. Move or delete the children first.';
        }

        if (\array_key_exists('published_at', $values)) {
            $violations += $this->publishedAtViolations($current, $this->date($values, 'published_at'));
        }

        $this->throwIfAny($violations);

        if ([] === $values) {
            return $current;
        }

        if ([] !== array_intersect_key($values, array_flip(['slug', 'parent_id', 'locale']))) {
            $slug = \array_key_exists('slug', $values) ? $this->string($values, 'slug') : $current->slug;
            $this->slugs->lockSiblings($type->id, $parentId, $locale);

            if ($this->slugs->isTaken($slug, $type->id, $parentId, $locale, $current->id)) {
                throw InvalidContentException::single('slug', \sprintf('Another %s here already uses the slug "%s".', strtolower($type->label), $slug));
            }
        }

        if (\array_key_exists('locale', $values)) {
            $values['search_config'] = Locales::searchConfiguration($locale);
        }

        $after = $this->saveChanges($current, $values);

        // Changes to what visitors see, or will at its time, pass the content checks.
        if (\in_array($after->status, [Status::PUBLISHED, Status::SCHEDULED], true)) {
            $this->assertPassesChecks($after);
        }

        if (\array_key_exists('fields', $values)) {
            $this->relations->sync($after->id, $type, $after->fields, $current->fields);
        }

        if ($type->has(Capability::REVISIONS) && [] !== array_intersect_key($values, array_flip(self::REVISIONED))) {
            $this->saveRevision($after, $command->revisionMessage, $command->actorId, $approvedBy);
        }

        $this->assertReachable($after);
        $events->append(new ContentUpdated($current, $after, $published));

        return $after;
    }

    /**
     * Runs an operation in a transaction and dispatches the events it collected once it committed.
     *
     * @template T
     *
     * @param \Closure(\ArrayObject<int, object>): T $operation
     *
     * @return T
     */
    private function write(\Closure $operation): mixed
    {
        /** @var \ArrayObject<int, object> $events */
        $events = new \ArrayObject();

        try {
            $result = $this->connection->transactional(static fn (): mixed => $operation($events));
        } catch (ForeignKeyConstraintViolationException $e) {
            // An author deleted meanwhile, or who made the change: a user who doesn't exist.
            throw match (true) {
                str_contains($e->getMessage(), 'content_author_fk') => InvalidContentException::single('author_id', 'No user has this ID.'),
                str_contains($e->getMessage(), 'content_revision_author_fk') => InvalidContentException::single('actor_id', 'No user has this ID.'),
                default => $e,
            };
        } catch (UniqueConstraintViolationException $e) {
            // The checks above make these unlikely; this covers concurrent writers that got past them.
            throw match (true) {
                str_contains($e->getMessage(), 'content_slug_unique') => InvalidContentException::single('slug', 'Another item here already uses this slug.'),
                str_contains($e->getMessage(), 'content_translation_unique') => InvalidContentException::single('locale', 'The item already has a translation in this language.'),
                default => $e,
            };
        }

        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }

        return $result;
    }

    private function dispatchCancellable(CancellableEvent $event): void
    {
        $this->dispatcher->dispatch($event);

        if ($event->isCancelled()) {
            throw new ContentOperationCancelledException($event->cancelReason() ?? 'The change was cancelled.');
        }
    }

    /**
     * Refuses URLs that a route of the site takes, where visitors would never reach the item.
     */
    private function assertReachable(Content $content): void
    {
        $url = $this->permalinks->url($content);
        $route = '/' === $this->permalinks->path($content) ? null : $this->routes->routeTaking($url);

        if (null !== $route) {
            throw InvalidContentException::single('slug', \sprintf('The URL %s is taken by a page of the site itself (the route "%s"). Choose another slug.', $url, $route));
        }
    }

    private function lockEditable(string $id, int $expectedVersion): Content
    {
        $current = $this->repository->lock($id);

        if ($current->version !== $expectedVersion) {
            throw new ContentConflictException($current->id, $expectedVersion, $current->version);
        }

        if ($current->isTrashed()) {
            throw InvalidContentException::single('deleted_at', 'The item is in the trash. Restore it before changing it.');
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $values Columns to set
     */
    private function saveChanges(Content $current, array $values): Content
    {
        $assignments = [];
        $parameters = ['id' => $current->id, 'version' => $current->version];

        foreach ($values as $column => $value) {
            $assignments[] = 'search_config' === $column ? 'search_config = CAST(:search_config AS regconfig)' : \sprintf('%1$s = :%1$s', $column);
            $parameters[$column] = match (true) {
                $value instanceof \DateTimeImmutable => $value->format('Y-m-d H:i:sP'),
                'fields' === $column => $this->json($value),
                default => $value,
            };
        }

        $updated = $this->connection->executeStatement(
            \sprintf('UPDATE quire.content SET %s, version = version + 1, updated_at = now() WHERE id = :id AND version = :version', implode(', ', $assignments)),
            $parameters,
        );

        if (1 !== $updated) {
            throw new ContentConflictException($current->id, $current->version, $this->repository->get($current->id)->version);
        }

        return $this->repository->get($current->id);
    }

    /**
     * @return array<string, mixed> The requested values that differ from the item's, keyed by column
     */
    private function changedValues(Content $current, UpdateContent $command, ContentTypeDefinition $type): array
    {
        $values = $command->changes();

        if ([] !== $command->fieldChanges()) {
            $fields = \array_key_exists('fields', $values) && \is_array($values['fields']) ? $values['fields'] : $current->fields;

            foreach ($command->fieldChanges() as $name => $value) {
                if (null === $value) {
                    unset($fields[$name]);
                } else {
                    $fields[$name] = $value;
                }
            }

            $values['fields'] = $fields;
        }

        if (\array_key_exists('fields', $values) && \is_array($values['fields']) && ([] === $values['fields'] || !array_is_list($values['fields']))) {
            /** @var array<string, mixed> $requested */
            $requested = $values['fields'];
            $values['fields'] = Fields::normalize($type, $requested);
        }

        if (\array_key_exists('title', $values) && \is_string($values['title'])) {
            $values['title'] = trim($values['title']);
        }

        $currentValues = [
            'title' => $current->title, 'slug' => $current->slug, 'body' => $current->body, 'body_format' => $current->bodyFormat,
            'excerpt' => $current->excerpt, 'fields' => $current->fields, 'parent_id' => $current->parentId,
            'position' => $current->position, 'locale' => $current->locale, 'author_id' => $current->authorId,
            'published_at' => $current->publishedAt,
        ];

        return array_filter(
            $values,
            static fn (mixed $value, string $column): bool => !Values::same($value, $currentValues[$column]),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array<string, mixed> $values Values to check, keyed by column; only those present are checked
     *
     * @return array<string, string> Messages keyed by property
     */
    private function validate(ContentTypeDefinition $type, array $values): array
    {
        $violations = [];

        foreach ($values as $column => $value) {
            $message = match ($column) {
                'title' => match (true) {
                    !\is_string($value) || '' === trim($value) => 'The title can\'t be empty.',
                    mb_strlen($value) > self::MAX_TITLE_LENGTH => \sprintf('The title can be at most %d characters long.', self::MAX_TITLE_LENGTH),
                    default => null,
                },
                'locale' => \is_string($value) && Locales::isValid($value) ? null : 'Use a language tag such as "en", "de" or "pt-BR".',
                'body_format' => \is_string($value) && \in_array($value, $this->formatter->profile('content')->formats, true) ? null : \sprintf('Unknown format. Use one of: %s.', implode(', ', $this->formatter->profile('content')->formats)),
                'excerpt' => null === $value || $type->has(Capability::EXCERPT) ? null : \sprintf('%s have no excerpt.', $type->pluralLabel),
                'author_id' => match (true) {
                    null === $value => null,
                    !$type->has(Capability::AUTHOR) => \sprintf('%s have no author.', $type->pluralLabel),
                    !\is_string($value) || !Row::isUuid($value) || false === $this->connection->fetchOne('SELECT 1 FROM quire.app_user WHERE id = ?', [$value]) => 'No user has this ID.',
                    default => null,
                },
                'fields' => \is_array($value) && ([] === $value || !array_is_list($value)) ? null : 'Fields must be an object of names and values.',
                'position' => \is_int($value) ? null : 'The position must be a whole number.',
                default => null,
            };

            if (null !== $message) {
                $violations[$column] = $message;
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    private function parentViolations(ContentTypeDefinition $type, string $parentId, string $locale, ?string $selfId): array
    {
        if (!$type->has(Capability::HIERARCHICAL)) {
            return ['parent_id' => \sprintf('%s aren\'t hierarchical, so they can\'t have a parent.', $type->pluralLabel)];
        }

        $parent = $this->repository->find($parentId);

        $message = match (true) {
            !$parent instanceof Content => \sprintf('No content item with ID "%s" exists.', $parentId),
            $parent->type !== $type->id => \sprintf('The parent must be a %s too.', strtolower($type->label)),
            $parent->isTrashed() => 'The parent is in the trash.',
            $parent->locale !== $locale => \sprintf('The parent must be in the same language (%s).', $locale),
            null !== $selfId && $this->isSelfOrAncestor($selfId, $parentId) => 'An item can\'t be moved below itself.',
            default => null,
        };

        return null === $message ? [] : ['parent_id' => $message];
    }

    private function isSelfOrAncestor(string $id, string $of): bool
    {
        return false !== $this->connection->fetchOne(
            'WITH RECURSIVE ancestor AS (
                 SELECT id, parent_id FROM quire.content WHERE id = :of
                 UNION ALL
                 SELECT c.id, c.parent_id FROM quire.content c JOIN ancestor a ON c.id = a.parent_id
             )
             SELECT 1 FROM ancestor WHERE id = :id',
            ['id' => $id, 'of' => $of],
        );
    }

    /**
     * @return array{?string, array<string, string>} The translation group to join, and violations
     */
    private function translationGroup(string $sourceId, ContentTypeDefinition $type, string $locale): array
    {
        $source = $this->repository->find($sourceId);

        if (!$source instanceof Content) {
            return [null, ['translation_of' => \sprintf('No content item with ID "%s" exists.', $sourceId)]];
        }

        $message = match (true) {
            $source->type !== $type->id => \sprintf('A translation must be a %s, like the item it translates.', strtolower($type->label)),
            $source->isTrashed() => 'The item to translate is in the trash.',
            $source->locale === $locale => \sprintf('The item to translate is already in %s.', $locale),
            default => null,
        };

        if (null !== $message) {
            return [null, ['translation_of' => $message]];
        }

        if (null === $source->translationGroupId) {
            // The first translation starts the group, named after the original. Not a content change: no new version.
            $this->connection->executeStatement('UPDATE quire.content SET translation_group_id = id WHERE id = ?', [$source->id]);

            return [$source->id, []];
        }

        $taken = $this->connection->fetchOne('SELECT id FROM quire.content WHERE translation_group_id = ? AND locale = ?', [$source->translationGroupId, $locale]);

        return [$source->translationGroupId, false === $taken ? [] : ['locale' => \sprintf('The item already has a translation in %s.', $locale)]];
    }

    /**
     * @return array<string, string>
     */
    private function publishedAtViolations(Content $current, \DateTimeImmutable $publishedAt): array
    {
        return match (true) {
            !\in_array($current->status, [Status::PUBLISHED, Status::SCHEDULED], true) => ['published_at' => 'Only published and scheduled items have a publication date. Publish or schedule the item instead.'],
            Status::PUBLISHED === $current->status && $publishedAt > new \DateTimeImmutable() => ['published_at' => 'A published item can\'t have a future date. Use the "schedule" transition.'],
            default => [],
        };
    }

    private function claimSlug(?string $requested, string $title, string $type, ?string $parentId, string $locale, ?string $exceptId): string
    {
        if (null === $requested) {
            return $this->slugs->unique(Slugs::fromTitle($title, $locale), $type, $parentId, $locale, $exceptId);
        }

        if ($this->slugs->isTaken($requested, $type, $parentId, $locale, $exceptId)) {
            throw InvalidContentException::single('slug', \sprintf('Another item here already uses the slug "%s".', $requested));
        }

        return $requested;
    }

    private function nextPosition(string $type, ?string $parentId, string $locale): int
    {
        $next = $this->connection->fetchOne(
            'SELECT COALESCE(MAX(position) + 1, 0) FROM quire.content
             WHERE type = :type AND parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND locale = :locale AND deleted_at IS NULL',
            ['type' => $type, 'parent' => $parentId, 'locale' => $locale],
        );

        return is_numeric($next) ? (int) $next : 0;
    }

    private function hasChildren(string $id, bool $includeTrashed): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM quire.content WHERE parent_id = ?'.($includeTrashed ? '' : ' AND deleted_at IS NULL').' LIMIT 1',
            [$id],
        );
    }

    private function saveRevision(Content $content, ?string $message, ?string $actorId, ?string $approvedBy = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO quire.content_revision (content_id, content_version, title, excerpt, body, body_format, fields, author_id, message, approved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$content->id, $content->version, $content->title, $content->excerpt, $content->body, $content->bodyFormat, $this->json($content->fields), $actorId, $message, $approvedBy],
        );

        $keep = $this->settings->getInt('quire.content.revisions_keep');

        if ($keep > 0) {
            $this->connection->executeStatement(
                'DELETE FROM quire.content_revision WHERE content_id = :id AND content_version < (
                     SELECT content_version FROM quire.content_revision WHERE content_id = :id ORDER BY content_version DESC OFFSET :offset LIMIT 1
                 )',
                ['id' => $content->id, 'offset' => $keep - 1],
            );
        }
    }

    private function invalidSlugMessage(): string
    {
        return \sprintf('Slugs are lowercase letters and digits separated by hyphens, e.g. "my-first-post", up to %d characters.', Slugs::MAX_LENGTH);
    }

    /**
     * @param array<string, string> $violations
     */
    private function throwIfAny(array $violations): void
    {
        if ([] !== $violations) {
            throw new InvalidContentException($violations);
        }
    }

    private function json(mixed $value): string
    {
        // An empty PHP array is a list for json_encode(); fields are always an object.
        return [] === $value ? '{}' : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function string(array $values, string $key): string
    {
        return \is_string($values[$key]) ? $values[$key] : throw new \LogicException(\sprintf('"%s" must be a string.', $key));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function nullableString(array $values, string $key): ?string
    {
        return null === $values[$key] ? null : $this->string($values, $key);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function date(array $values, string $key): \DateTimeImmutable
    {
        return $values[$key] instanceof \DateTimeImmutable ? $values[$key] : throw new \LogicException(\sprintf('"%s" must be a date.', $key));
    }
}
