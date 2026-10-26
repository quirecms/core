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
     * @param string|null $action An action, e.g. "user.suspended", or a prefix ending with a dot, e.g. "content."
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
    ) {
    }
}
