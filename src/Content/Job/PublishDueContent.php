<?php

declare(strict_types=1);

namespace Quire\Content\Job;

use Quire\Jobs\Job;

/**
 * Publishes scheduled items whose date has passed (scheduled every minute as "quire.publish-due"), so
 * listeners of {@see \Quire\Content\Event\ContentPublished} run for them.
 *
 * @api
 */
final readonly class PublishDueContent implements Job
{
}
