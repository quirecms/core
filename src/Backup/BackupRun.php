<?php

declare(strict_types=1);

namespace Quire\Backup;

/**
 * A backup Quire made, or tried to make.
 *
 * @api
 */
final readonly class BackupRun
{
    /**
     * @param int|null    $bytes   How big it is
     * @param string|null $target  The file it was written to; null when it was streamed, e.g. to another program
     * @param string|null $problem Why it failed
     */
    public function __construct(
        public \DateTimeImmutable $finishedAt,
        public bool $ok,
        public ?int $bytes = null,
        public ?string $target = null,
        public ?string $problem = null,
    ) {
    }
}
