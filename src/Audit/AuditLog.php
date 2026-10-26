<?php

declare(strict_types=1);

namespace Quire\Audit;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Quire\Audit\Event\AuditEntryRecorded;
use Quire\Audit\Internal\AuditContext;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The audit log: who did what, when, and how (in the admin, on the command line, in a background job).
 *
 * Core records its own actions from its events (content, users, roles and permissions, settings,
 * themes, extensions, sign-ins). Extensions record theirs with {@see record()}, e.g. from a listener of
 * their own events. Details must never hold secrets (passwords, tokens, sensitive settings), and should
 * point to data rather than copy it, e.g. a revision's ID rather than the text.
 *
 * Recording never fails the action: when the entry can't be written, the error is logged.
 *
 * @api
 */
final class AuditLog implements ResetInterface
{
    /** Repeated actions within this many minutes update one entry, with {@see record()}'s $coalesce */
    public const int COALESCE_MINUTES = 15;

    public const string RETENTION_SETTING = 'quire.audit.retention_days';

    public const string ABILITY = 'quire.audit.view';

    private const string COLUMNS = 'id, occurred_at, last_at, repeats, action, actor_id, actor_name, origin, via, subject_type, subject_id, subject_label, details, command, correlation_id';

    /**
     * Entries of the current request, command or job, by ID. Only the current one's are kept, so a
     * long-running command (thousands of actions) doesn't hold them all.
     *
     * @var array<string, AuditEntry>
     */
    private array $recorded = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditContext $context,
        private readonly UserRepository $users,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Adds an entry for the current user (or console command, or job).
     *
     * @param string               $action   What happened: your extension's ID, a dot and a verb in the past tense, e.g. "acme-shop.order_refunded"
     * @param array<string, mixed> $details  JSON-encodable; never secrets
     * @param string|null          $command  The console command that does the same, e.g. "bin/console acme-shop:refund 1042"
     * @param string|null          $actorId  Who did it when nobody is signed in, e.g. who scheduled what a job does now
     * @param bool                 $coalesce Whether a repeat of this action on this subject by the same user within
     *                                       {@see COALESCE_MINUTES} updates their last entry instead, e.g. for autosaving
     *
     * @return AuditEntry|null Null when it couldn't be written
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $subjectLabel = null,
        array $details = [],
        ?string $command = null,
        ?string $actorId = null,
        bool $coalesce = false,
    ): ?AuditEntry {
        try {
            $user = $this->context->user();
            $actorId = $user->id ?? $actorId;
            $actorName = $user instanceof User ? $user->displayName : (null === $actorId ? null : $this->users->find($actorId)?->displayName);
            $origin = $this->context->origin();
            $correlationId = $this->context->correlationId();
            $json = $this->json($details);

            // A savepoint inside the caller's transaction: a failure here doesn't abort theirs.
            $row = $this->connection->transactional(function () use ($action, $subjectType, $subjectId, $subjectLabel, $details, $json, $command, $actorId, $actorName, $origin, $correlationId, $coalesce): array|false {
                $previous = $coalesce && null !== $subjectId ? $this->connection->fetchAssociative(
                    'SELECT id, details FROM quire.audit_entry
                     WHERE action = ? AND subject_type IS NOT DISTINCT FROM ? AND subject_id = ? AND actor_id IS NOT DISTINCT FROM ? AND origin = ?
                       AND last_at > statement_timestamp() - make_interval(mins => ?)
                     ORDER BY last_at DESC LIMIT 1
                     FOR UPDATE',
                    [$action, $subjectType, $subjectId, $actorId, $origin->value, self::COALESCE_MINUTES],
                ) : false;

                if (false !== $previous) {
                    return $this->connection->fetchAssociative(
                        'UPDATE quire.audit_entry SET last_at = statement_timestamp(), repeats = repeats + 1, details = ?::jsonb, command = ?, subject_label = ?
                         WHERE id = ? RETURNING '.self::COLUMNS,
                        [$this->json($this->merge($this->details($previous['details']), $details)), $command, $subjectLabel, $previous['id']],
                    );
                }

                return $this->connection->fetchAssociative(
                    'INSERT INTO quire.audit_entry (action, actor_id, actor_name, origin, via, subject_type, subject_id, subject_label, details, command, correlation_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
                     RETURNING '.self::COLUMNS,
                    [$action, $actorId, $actorName, $origin->value, $this->context->via(), $subjectType, $subjectId, $subjectLabel, $json, $command, $correlationId],
                );
            });
        } catch (\Throwable $e) {
            $this->logger?->error('Couldn\'t record "{action}" in the audit log: {message}', ['action' => $action, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        return \is_array($row) ? $this->recorded($this->entry($row)) : null;
    }

    /**
     * Changes an entry this process recorded, e.g. when a later event says more about the same action.
     *
     * @param array<string, mixed> $details Merged into the entry's
     *
     * @internal
     */
    public function amend(AuditEntry $entry, string $action, array $details = [], ?string $command = null, ?string $actorId = null): ?AuditEntry
    {
        try {
            $actorName = null === $actorId ? $entry->actorName : $this->users->find($actorId)?->displayName;
            $row = $this->connection->fetchAssociative(
                'UPDATE quire.audit_entry SET action = ?, details = details || ?::jsonb, command = ?, actor_id = ?, actor_name = ? WHERE id = ? RETURNING '.self::COLUMNS,
                [$action, $this->json($details), $command, $actorId ?? $entry->actorId, $actorName, $entry->id],
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Couldn\'t record "{action}" in the audit log: {message}', ['action' => $action, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        if (!\is_array($row)) {
            return null;
        }

        unset($this->recorded[$entry->id]);

        return $this->recorded($this->entry($row));
    }

    /**
     * The entries this process recorded during the current request, command or job (until reset),
     * oldest first, e.g. to show the user the commands that do the same.
     *
     * @return list<AuditEntry>
     */
    public function recordedNow(): array
    {
        $correlationId = $this->context->correlationId();

        return array_values(array_filter($this->recorded, static fn (AuditEntry $entry): bool => $entry->correlationId === $correlationId));
    }

    /**
     * @param int $page Starting at 1
     */
    public function find(AuditQuery $query = new AuditQuery(), int $page = 1, int $perPage = 50): AuditPage
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        [$where, $parameters] = $this->where($query);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.', count(*) OVER () AS total FROM quire.audit_entry'.$where.' ORDER BY last_at DESC, id DESC LIMIT ? OFFSET ?',
            [...$parameters, $perPage, ($page - 1) * $perPage],
        );
        $total = [] === $rows ? $this->count($where, $parameters) : (int) $this->scalar($rows[0]['total'] ?? 0);

        return new AuditPage(array_map($this->entry(...), $rows), $page, $perPage, $total);
    }

    /**
     * How many entries match on each day, in a time zone, e.g. for bars of the last 30 days. Days without
     * any are left out.
     *
     * @return array<string, int> By day ("2026-10-26"), oldest first
     */
    public function countByDay(AuditQuery $query, \DateTimeZone $timezone): array
    {
        [$where, $parameters] = $this->where($query);
        $counts = [];

        foreach ($this->connection->fetchAllKeyValue("SELECT to_char(last_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) FROM quire.audit_entry".$where.' GROUP BY 1 ORDER BY 1', [$timezone->getName(), ...$parameters]) as $day => $count) {
            $counts[(string) $day] = (int) $this->scalar($count);
        }

        return $counts;
    }

    /**
     * How many entries match of each action, e.g. to filter by what was done.
     *
     * @return array<string, int> By action, most first
     */
    public function countByAction(AuditQuery $query): array
    {
        [$where, $parameters] = $this->where($query);
        $counts = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT action, count(*) FROM quire.audit_entry'.$where.' GROUP BY 1 ORDER BY 2 DESC, 1', $parameters) as $action => $count) {
            $counts[(string) $action] = (int) $this->scalar($count);
        }

        return $counts;
    }

    public function get(string $id): ?AuditEntry
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            return null;
        }

        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM quire.audit_entry WHERE id = ?', [$id]);

        return false === $row ? null : $this->entry($row);
    }

    /**
     * Deletes the entries last changed before the given time.
     *
     * @return int How many were deleted
     */
    public function prune(\DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM quire.audit_entry WHERE last_at < ?', [$before->format(\DATE_ATOM)]);
    }

    public function reset(): void
    {
        $this->recorded = [];
    }

    private function recorded(AuditEntry $entry): AuditEntry
    {
        $first = reset($this->recorded);

        if (false !== $first && $first->correlationId !== $entry->correlationId) {
            $this->recorded = [];
        }

        // Moved to the end when a repeated action updated it.
        unset($this->recorded[$entry->id]);
        $this->recorded[$entry->id] = $entry;

        try {
            $this->dispatcher->dispatch(new AuditEntryRecorded($entry));
        } catch (\Throwable $e) {
            $this->logger?->error('A listener of AuditEntryRecorded failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }

        return $entry;
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function where(AuditQuery $query): array
    {
        $conditions = [];
        $parameters = [];

        foreach (['actor_id' => $query->actorId, 'subject_type' => $query->subjectType, 'subject_id' => $query->subjectId, 'correlation_id' => $query->correlationId, 'origin' => $query->origin?->value] as $column => $value) {
            if (null !== $value) {
                $conditions[] = $column.' = ?';
                $parameters[] = $value;
            }
        }

        if (null !== $query->action) {
            $prefix = str_ends_with($query->action, '.');
            $conditions[] = $prefix ? "action LIKE ? ESCAPE '\\'" : 'action = ?';
            $parameters[] = $prefix ? addcslashes($query->action, '%_\\').'%' : $query->action;
        }

        if ([] !== $query->exceptActions) {
            $conditions[] = 'action NOT IN ('.implode(', ', array_fill(0, \count($query->exceptActions), '?')).')';
            array_push($parameters, ...$query->exceptActions);
        }

        $text = trim((string) $query->text);

        if ('' !== $text) {
            // No index: the activity is searched within a period, which the index on last_at narrows.
            $pattern = '%'.addcslashes($text, '%_\\').'%';
            $conditions[] = '(subject_label ILIKE ? OR actor_name ILIKE ? OR action ILIKE ?'.([] === $query->textActions ? '' : ' OR action IN ('.implode(', ', array_fill(0, \count($query->textActions), '?')).')').')';
            array_push($parameters, $pattern, $pattern, $pattern, ...$query->textActions);
        }

        if ($query->since instanceof \DateTimeImmutable) {
            $conditions[] = 'last_at >= ?';
            $parameters[] = $query->since->format(\DATE_ATOM);
        }

        if ($query->until instanceof \DateTimeImmutable) {
            $conditions[] = 'occurred_at < ?';
            $parameters[] = $query->until->format(\DATE_ATOM);
        }

        return [[] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions), $parameters];
    }

    /**
     * @param list<mixed> $parameters
     */
    private function count(string $where, array $parameters): int
    {
        return (int) $this->scalar($this->connection->fetchOne('SELECT count(*) FROM quire.audit_entry'.$where, $parameters));
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '0';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entry(array $row): AuditEntry
    {
        return new AuditEntry(
            id: $this->string($row['id']),
            occurredAt: new \DateTimeImmutable($this->string($row['occurred_at'])),
            action: $this->string($row['action']),
            actorId: $this->nullable($row['actor_id']),
            actorName: $this->nullable($row['actor_name']),
            origin: Origin::from($this->string($row['origin'])),
            via: $this->nullable($row['via']),
            subjectType: $this->nullable($row['subject_type']),
            subjectId: $this->nullable($row['subject_id']),
            subjectLabel: $this->nullable($row['subject_label']),
            details: $this->details($row['details']),
            command: $this->nullable($row['command']),
            correlationId: $this->string($row['correlation_id']),
            repeats: (int) $this->scalar($row['repeats']),
            lastAt: new \DateTimeImmutable($this->string($row['last_at'])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function details(mixed $json): array
    {
        $details = json_decode($this->string($json), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($details) ? array_filter($details, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * Details of a repeated action: lists are joined, other values replaced.
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    private function merge(array $previous, array $details): array
    {
        foreach ($details as $key => $value) {
            $previous[$key] = \is_array($value) && array_is_list($value) && \is_array($previous[$key] ?? null) && array_is_list($previous[$key])
                ? array_values(array_unique([...$previous[$key], ...$value], \SORT_REGULAR))
                : $value;
        }

        return $previous;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function json(array $details): string
    {
        return json_encode((object) $details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A column of quire.audit_entry isn\'t text.');
    }

    private function nullable(mixed $value): ?string
    {
        return null === $value ? null : $this->string($value);
    }
}
