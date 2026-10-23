<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * Where a pending revision is in review. Review is optional: unless the site requires approval
 * ("quire.content.changes_need_approval"), changes can be published in any state.
 *
 * @api
 */
enum ReviewState: string
{
    /** Being written; nobody was asked to review it yet. */
    case Editing = 'editing';

    /** Its author asked for a review, or changed it after it was approved. */
    case InReview = 'in_review';

    /** A reviewer asked for changes. */
    case ChangesRequested = 'changes_requested';

    /** A reviewer approved it as it is now. Changing it asks for a review again. */
    case Approved = 'approved';
}
