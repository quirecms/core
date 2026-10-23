<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * What a reviewer says about a pending revision.
 *
 * @api
 */
enum Verdict: string
{
    case Approve = 'approve';

    case RequestChanges = 'request_changes';

    /** A remark that doesn't change the review state. */
    case Comment = 'comment';
}
