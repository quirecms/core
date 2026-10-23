<?php

declare(strict_types=1);

namespace Quire\Jobs;

/**
 * A message handled in the background: dispatch it on the message bus, and a worker handles it.
 *
 *     final readonly class GenerateVariants implements Job
 *     {
 *         public function __construct(public string $objectId) {}
 *     }
 *
 *     $bus->dispatch(new GenerateVariants($object->id));
 *
 * Messages implementing this interface go to the transport "quire": the table quire.job by default,
 * written in the current database transaction, so a job dispatched while saving content exists if,
 * and only if, the change is committed. Failed jobs are retried, then kept in "quire_failed".
 *
 * Keep messages small and serializable: IDs rather than objects, since the item may have changed by
 * the time the job runs.
 *
 * @api
 */
interface Job
{
    /** The transport jobs go to */
    public const string TRANSPORT = 'quire';

    /** Where jobs go when they failed after their retries */
    public const string FAILED_TRANSPORT = 'quire_failed';
}
