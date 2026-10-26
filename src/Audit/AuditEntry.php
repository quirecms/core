<?php

declare(strict_types=1);

namespace Quire\Audit;

/**
 * One action in the audit log: who did what to what, when, and how.
 *
 * @api
 */
final readonly class AuditEntry
{
    /**
     * @param string               $action        What happened, e.g. "content.transitioned" or "acme-shop.order_refunded"
     * @param string|null          $actorId       The user who did it; null for guests, the command line, jobs, and deleted users
     * @param string|null          $actorName     Their name at the time, kept when the user is deleted
     * @param string|null          $via           What ran: the route, console command or job, e.g. "quire:user:suspend"
     * @param string|null          $subjectType   What the action was about, e.g. "content", "user" or "setting"
     * @param string|null          $subjectLabel  Its name at the time, e.g. the title of a post
     * @param array<string, mixed> $details       More about it, e.g. the fields that changed; never secrets
     * @param string|null          $command       The console command that does the same, when there is one
     * @param string               $correlationId The same for every action of one request, command or job
     * @param int                  $repeats       How often it happened: repeated saves of an item update one entry
     * @param \DateTimeImmutable   $lastAt        When it last happened; the same as $occurredAt unless repeated
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $occurredAt,
        public string $action,
        public ?string $actorId,
        public ?string $actorName,
        public Origin $origin,
        public ?string $via,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $subjectLabel,
        public array $details,
        public ?string $command,
        public string $correlationId,
        public int $repeats,
        public \DateTimeImmutable $lastAt,
    ) {
    }

    /**
     * The entry as data, e.g. one line of a JSON Lines export: what "quire:audit:list --format jsonl" prints.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurredAt->format(\DATE_ATOM),
            'last_at' => $this->lastAt->format(\DATE_ATOM),
            'repeats' => $this->repeats,
            'action' => $this->action,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'origin' => $this->origin->value,
            'via' => $this->via,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'details' => (object) $this->details,
            'command' => $this->command,
            'correlation_id' => $this->correlationId,
        ];
    }
}
