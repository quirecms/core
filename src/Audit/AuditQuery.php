<?php

declare(strict_types=1);

namespace Quire\Audit;

/**
 * Which entries of the audit log to find; criteria left null don't filter.
 *
 * @api
 */
final readonly class AuditQuery
{
    /**
     * @param string|null  $action        An action, e.g. "user.suspended", or a prefix ending with a dot, e.g. "content."
     * @param list<string> $exceptActions Actions left out, e.g. ["user.signed_in"] for what people did
     * @param string|null  $text          Part of the subject's label, the actor's name or the action, e.g. "Spring draw" or "grace"
     * @param list<string> $textActions   Actions the text matches too, e.g. those an interface describes with the word searched for
     */
    public function __construct(
        public ?string $actorId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $action = null,
        public ?Origin $origin = null,
        public ?string $correlationId = null,
        public ?\DateTimeImmutable $since = null,
        public ?\DateTimeImmutable $until = null,
        public array $exceptActions = [],
        public ?string $text = null,
        public array $textActions = [],
    ) {
    }
}
