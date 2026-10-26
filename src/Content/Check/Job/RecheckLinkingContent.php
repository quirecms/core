<?php

declare(strict_types=1);

namespace Quire\Content\Check\Job;

use Quire\Jobs\Job;

/**
 * Checks again the items that may link to an item that changed, e.g. was unpublished or got another
 * slug: dispatched by Quire, in the background, so saving the item doesn't wait for it.
 *
 * @api
 */
final readonly class RecheckLinkingContent implements Job
{
    /**
     * @param list<string> $slugs  The item's slugs, before and after
     * @param string|null  $except The item's ID: it was checked already
     */
    public function __construct(
        public array $slugs,
        public ?string $except = null,
    ) {
    }
}
