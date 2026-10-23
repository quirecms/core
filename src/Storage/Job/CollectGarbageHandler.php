<?php

declare(strict_types=1);

namespace Quire\Storage\Job;

use Quire\Settings\Settings;
use Quire\Storage\Internal\GarbageCollector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class CollectGarbageHandler
{
    public const string GRACE_SETTING = 'quire.storage.gc_grace_days';

    public function __construct(
        private GarbageCollector $collector,
        private Settings $settings,
    ) {
    }

    public function __invoke(CollectGarbage $job): void
    {
        $days = max(1, $this->settings->getInt(self::GRACE_SETTING));
        $this->collector->collect(new \DateTimeImmutable(\sprintf('-%d days', $days)));
    }
}
