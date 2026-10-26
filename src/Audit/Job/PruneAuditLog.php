<?php

declare(strict_types=1);

namespace Quire\Audit\Job;

use Quire\Jobs\Job;

/**
 * Deletes audit entries older than "quire.audit.retention_days" days (scheduled daily as
 * "quire.prune-audit-log"); with 0 days, none.
 *
 * @api
 */
final readonly class PruneAuditLog implements Job
{
}
