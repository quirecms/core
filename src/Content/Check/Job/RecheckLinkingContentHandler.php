<?php

declare(strict_types=1);

namespace Quire\Content\Check\Job;

use Quire\Content\Check\CheckResults;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class RecheckLinkingContentHandler
{
    public function __construct(
        private CheckResults $results,
    ) {
    }

    public function __invoke(RecheckLinkingContent $job): void
    {
        $this->results->refreshLinkingTo($job->slugs, $job->except);
    }
}
