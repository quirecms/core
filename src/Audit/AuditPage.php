<?php

declare(strict_types=1);

namespace Quire\Audit;

/**
 * One page of audit entries, newest first, from {@see AuditLog::find()}.
 *
 * @api
 *
 * @implements \IteratorAggregate<int, AuditEntry>
 */
final readonly class AuditPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<AuditEntry> $entries
     * @param int              $page    Starting at 1
     * @param int              $total   How many entries match, on all pages
     */
    public function __construct(
        public array $entries,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->pageCount();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @return \ArrayIterator<int, AuditEntry>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->entries);
    }
}
