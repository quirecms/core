<?php

declare(strict_types=1);

namespace Quire\Audit\Event;

use Quire\Audit\AuditEntry;

/**
 * Dispatched after an entry was added to the audit log, or a repeated action updated one. Listen to it
 * to copy entries somewhere they can't be changed, e.g. a SIEM or append-only storage.
 *
 * @api
 */
final readonly class AuditEntryRecorded
{
    public function __construct(
        public AuditEntry $entry,
    ) {
    }
}
