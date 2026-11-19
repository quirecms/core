<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Quire\Content\Event\ContentDeleted;
use Quire\Content\Event\ContentPublished;
use Quire\Content\Event\ContentPurged;
use Quire\Content\Event\ContentRestored;
use Quire\Content\Event\ContentUnpublished;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Query\LatestPublished;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What LatestPublished keeps goes as soon as what it lists may have changed.
 *
 * @internal
 */
final readonly class ForgetLatestPublished
{
    public function __construct(
        private LatestPublished $latest,
    ) {
    }

    #[AsEventListener(ContentPublished::class)]
    #[AsEventListener(ContentUnpublished::class)]
    #[AsEventListener(ContentUpdated::class)]
    #[AsEventListener(ContentDeleted::class)]
    #[AsEventListener(ContentRestored::class)]
    #[AsEventListener(ContentPurged::class)]
    public function __invoke(): void
    {
        $this->latest->forget();
    }
}
