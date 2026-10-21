<?php

declare(strict_types=1);

namespace Quire\Content;

/**
 * The statuses and transitions of core's content workflow. Extensions can add their own with
 * {@see \Quire\Extend\ContentWorkflow}.
 *
 * @api
 */
final class Status
{
    /** Every new item starts here. Only editors see it. */
    public const string DRAFT = 'draft';

    /** Published at a future date: public once that date has passed. */
    public const string SCHEDULED = 'scheduled';

    public const string PUBLISHED = 'published';

    /** No longer public, but kept apart from drafts. */
    public const string ARCHIVED = 'archived';

    public const string PUBLISH = 'publish';

    public const string SCHEDULE = 'schedule';

    public const string UNPUBLISH = 'unpublish';

    public const string ARCHIVE = 'archive';

    private function __construct()
    {
    }
}
