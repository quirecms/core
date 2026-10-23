<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

use Quire\Content\Revision\Merge;

/**
 * The pending changes and what was published since they were started change the same things. Resolve
 * the conflicts (see {@see $conflicts}) and save the pending revision rebased onto the live version
 * ({@see \Quire\Content\Command\UpdatePendingRevision::rebase()}), then publish again.
 *
 * @api
 */
final class MergeConflictException extends \RuntimeException
{
    /**
     * @param array<string, string> $conflicts Messages keyed by property, like {@see InvalidContentException::$violations}
     */
    public function __construct(
        public readonly string $pendingRevisionId,
        public readonly array $conflicts,
        public readonly Merge $merge,
    ) {
        parent::__construct(\sprintf('The changes conflict with what was published since they were started: %s.', implode(', ', array_keys($conflicts))));
    }
}
