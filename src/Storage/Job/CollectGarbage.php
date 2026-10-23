<?php

declare(strict_types=1);

namespace Quire\Storage\Job;

use Quire\Jobs\Job;

/**
 * Deletes stored files that nothing has used for "quire.storage.gc_grace_days" days (scheduled daily as
 * "quire.collect-garbage"), like `quire:storage:gc`.
 *
 * @api
 */
final readonly class CollectGarbage implements Job
{
}
