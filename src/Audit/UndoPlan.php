<?php

declare(strict_types=1);

namespace Quire\Audit;

/**
 * How an entry of the audit log is undone: an action of the catalogue with its arguments, e.g.
 * "content.transition" with ["content" => …, "transition" => "unpublish"].
 *
 * @api
 */
final readonly class UndoPlan
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $action,
        public array $arguments,
    ) {
    }
}
