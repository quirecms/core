<?php

declare(strict_types=1);

namespace Quire\Content\Check\Job;

use Quire\Jobs\Job;

/**
 * Checks every item again and stores what the checks find (scheduled nightly as "quire.recheck-content"),
 * e.g. for links to other sites' pages that went away, or checks the site turned on.
 *
 * @api
 */
final readonly class RecheckContent implements Job
{
    /**
     * @param bool $onlyChanged Only items saved since they were last checked
     */
    public function __construct(
        public bool $onlyChanged = false,
    ) {
    }
}
