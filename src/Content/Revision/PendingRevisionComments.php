<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Content\Event\PendingRevisionCommented;
use Quire\Content\Event\PendingRevisionCommentResolved;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Internal\Row;

/**
 * Conversations about changes that aren't published yet: comments, optionally pointing at text, e.g.
 * "‘United’ has been the club's full name since August", with replies, marked resolved when settled.
 *
 * A comment points at text by quoting it, with the line it was on. As the changes are edited, the text
 * is found again, near where it was; when it's gone, the comment is outdated, and still shown with what
 * it quoted. Comments go with the pending revision when it's published or discarded; the audit log
 * keeps that they were made.
 *
 * Reviews (approve, ask for changes) are {@see PendingRevisionWriter::review()}.
 *
 * @api
 */
final readonly class PendingRevisionComments
{
    public const int MAX_LENGTH = 10000;

    public const int MAX_QUOTE_LENGTH = 500;

    private const string COLUMNS = 'id, pending_revision_id, parent_id, author_id, body, anchor_property, anchor_line, anchor_quote, anchor_version, resolved_at, resolved_by, created_at';

    public function __construct(
        private Connection $connection,
        private PendingRevisionRepository $pending,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Starts a thread, or replies to one ($replyTo, its first comment's ID; replies point at nothing).
     *
     * @throws \Quire\Content\Exception\ContentNotFoundException when the pending revision doesn't exist
     * @throws InvalidContentException                           when the comment isn't valid
     */
    public function add(string $pendingRevisionId, string $body, ?string $authorId = null, ?CommentAnchor $anchor = null, ?string $replyTo = null): Comment
    {
        $body = trim($body);
        $pending = $this->pending->get($pendingRevisionId);
        $violations = [];

        if ('' === $body || mb_strlen($body) > self::MAX_LENGTH) {
            $violations['body'] = \sprintf('A comment has from 1 to %d characters.', self::MAX_LENGTH);
        }

        if (null !== $replyTo) {
            $parent = $this->find($replyTo);

            if (!$parent instanceof Comment || $parent->pendingRevisionId !== $pending->id || null !== $parent->parentId) {
                $violations['reply_to'] = 'Replies are to the first comment of a thread on the same changes.';
            }

            if ($anchor instanceof CommentAnchor) {
                $violations['anchor'] = 'Replies point at what their thread points at.';
            }
        }

        if ($anchor instanceof CommentAnchor) {
            $quote = trim($anchor->quote);
            $text = $this->text($pending->proposed, $anchor->property);

            $violations += match (true) {
                null === $text => ['anchor' => \sprintf('Comments point at the body, title, excerpt or a field, not "%s".', $anchor->property)],
                '' === $quote || str_contains($quote, "\n") || mb_strlen($quote) > self::MAX_QUOTE_LENGTH => ['anchor' => \sprintf('Quote text on one line, of at most %d characters.', self::MAX_QUOTE_LENGTH)],
                null === $this->findLine($text, $quote, $anchor->line) => ['anchor' => 'The quoted text isn\'t in the changes.'],
                default => [],
            };
        }

        if ([] !== $violations) {
            throw new InvalidContentException($violations);
        }

        $line = $anchor instanceof CommentAnchor ? $this->findLine((string) $this->text($pending->proposed, $anchor->property), trim($anchor->quote), $anchor->line) : null;

        try {
            $id = $this->connection->fetchOne(
                'INSERT INTO quire.content_revision_comment (pending_revision_id, parent_id, author_id, body, anchor_property, anchor_line, anchor_quote, anchor_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$pending->id, $replyTo, $authorId, $body, $anchor?->property, $line, $anchor instanceof CommentAnchor ? trim($anchor->quote) : null, $anchor instanceof CommentAnchor ? $pending->version : null],
            );
        } catch (ForeignKeyConstraintViolationException) {
            throw InvalidContentException::single('author_id', 'No user has this ID.');
        }

        $comment = $this->get(\is_string($id) ? $id : throw new \LogicException('The comment just saved has no ID.'));
        $this->dispatcher->dispatch(new PendingRevisionCommented($pending, $comment));

        return $comment;
    }

    /**
     * Marks a thread resolved, or open again.
     *
     * @throws InvalidContentException when the comment isn't a thread's first
     */
    public function resolve(string $commentId, ?string $actorId = null, bool $resolved = true): Comment
    {
        $comment = $this->get($commentId);

        if (null !== $comment->parentId) {
            throw InvalidContentException::single('comment', 'Threads are resolved by their first comment.');
        }

        if ($comment->isResolved() === $resolved) {
            return $comment;
        }

        $this->connection->executeStatement(
            'UPDATE quire.content_revision_comment SET resolved_at = CASE WHEN ? THEN statement_timestamp() END, resolved_by = CASE WHEN ? THEN CAST(? AS uuid) END WHERE id = ?',
            [$resolved, $resolved, $actorId, $comment->id],
            [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
        $comment = $this->get($commentId);
        $this->dispatcher->dispatch(new PendingRevisionCommentResolved($this->pending->get($comment->pendingRevisionId), $comment, $actorId));

        return $comment;
    }

    public function find(string $id): ?Comment
    {
        if (!Row::isUuid($id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(\sprintf('SELECT %s FROM quire.content_revision_comment WHERE id = ?', self::COLUMNS), [$id]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @throws InvalidContentException when there's no such comment
     */
    public function get(string $id): Comment
    {
        return $this->find($id) ?? throw InvalidContentException::single('comment', \sprintf('No comment has the ID "%s".', $id));
    }

    /**
     * The threads on a pending revision, oldest first, with where their text is in its current version.
     *
     * @return list<CommentThread>
     */
    public function threads(PendingRevision $pending): array
    {
        $comments = array_map($this->hydrate(...), $this->connection->fetchAllAssociative(\sprintf('SELECT %s FROM quire.content_revision_comment WHERE pending_revision_id = ? ORDER BY created_at, id', self::COLUMNS), [$pending->id]));
        $replies = [];

        foreach ($comments as $comment) {
            if (null !== $comment->parentId) {
                $replies[$comment->parentId][] = $comment;
            }
        }

        $threads = [];

        foreach ($comments as $comment) {
            if (null !== $comment->parentId) {
                continue;
            }

            $anchor = $comment->anchor;
            $text = null === $anchor ? null : $this->text($pending->proposed, $anchor->property);
            $line = null === $anchor || null === $text ? null : $this->findLine($text, $anchor->quote, $anchor->line);
            $threads[] = new CommentThread($comment, $replies[$comment->id] ?? [], 'body' === $anchor?->property ? $line : null, null !== $anchor && null === $line);
        }

        return $threads;
    }

    /**
     * How many threads on each pending revision aren't resolved, e.g. for lists.
     *
     * @param list<string> $pendingRevisionIds
     *
     * @return array<string, int>
     */
    public function openCounts(array $pendingRevisionIds): array
    {
        if ([] === $pendingRevisionIds) {
            return [];
        }

        $counts = [];

        foreach ($this->connection->fetchAllAssociative('SELECT pending_revision_id::text AS id, count(*) AS open FROM quire.content_revision_comment WHERE parent_id IS NULL AND resolved_at IS NULL AND pending_revision_id::text IN (?) GROUP BY 1', [$pendingRevisionIds], [\Doctrine\DBAL\ArrayParameterType::STRING]) as $row) {
            $row = new Row($row);
            $counts[$row->string('id')] = $row->int('open');
        }

        return $counts;
    }

    /**
     * The text of a property of the changes; null for a property that isn't text.
     */
    private function text(Snapshot $snapshot, string $property): ?string
    {
        return match (true) {
            'body' === $property => $snapshot->body,
            'title' === $property => $snapshot->title,
            'excerpt' === $property => $snapshot->excerpt ?? '',
            str_starts_with($property, 'fields.') => \is_string($value = $snapshot->fields[substr($property, \strlen('fields.'))] ?? null) ? $value : null,
            default => null,
        };
    }

    /**
     * The line, from 1, that has the quote, the nearest to where it was; null when no line has it.
     */
    private function findLine(string $text, string $quote, int $near): ?int
    {
        $best = null;

        foreach (explode("\n", $text) as $index => $line) {
            if (str_contains($line, $quote) && (null === $best || abs($index + 1 - $near) < abs($best - $near))) {
                $best = $index + 1;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function hydrate(array $values): Comment
    {
        $row = new Row($values);
        $property = $row->nullableString('anchor_property');

        return new Comment(
            id: $row->string('id'),
            pendingRevisionId: $row->string('pending_revision_id'),
            parentId: $row->nullableString('parent_id'),
            authorId: $row->nullableString('author_id'),
            body: $row->string('body'),
            anchor: null === $property ? null : new CommentAnchor($property, $row->string('anchor_quote'), \is_int($values['anchor_line']) ? $values['anchor_line'] : 1, \is_int($values['anchor_version']) ? $values['anchor_version'] : null),
            createdAt: $row->date('created_at'),
            resolvedAt: $row->nullableDate('resolved_at'),
            resolvedBy: $row->nullableString('resolved_by'),
        );
    }
}
