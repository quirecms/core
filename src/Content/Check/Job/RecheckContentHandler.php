<?php

declare(strict_types=1);

namespace Quire\Content\Check\Job;

use Quire\Content\Check\CheckResults;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class RecheckContentHandler
{
    public function __construct(
        private CheckResults $results,
    ) {
    }

    public function __invoke(RecheckContent $job): void
    {
        $after = null;

        do {
            ['last' => $after] = $this->results->refreshAll($job->onlyChanged, $after);
        } while (null !== $after);
    }
}
