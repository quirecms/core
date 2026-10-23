<?php

declare(strict_types=1);

namespace Quire\Content\Job;

use Quire\Content\ContentWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class PublishDueContentHandler
{
    public function __construct(
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(PublishDueContent $job): void
    {
        $this->writer->publishDue();
    }
}
