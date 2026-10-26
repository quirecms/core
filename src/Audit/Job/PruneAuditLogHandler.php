<?php

declare(strict_types=1);

namespace Quire\Audit\Job;

use Quire\Audit\AuditLog;
use Quire\Settings\Settings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @internal
 */
#[AsMessageHandler]
final readonly class PruneAuditLogHandler
{
    public function __construct(
        private AuditLog $log,
        private Settings $settings,
    ) {
    }

    public function __invoke(PruneAuditLog $job): void
    {
        $days = $this->settings->getInt(AuditLog::RETENTION_SETTING);

        if ($days > 0) {
            $this->log->prune(new \DateTimeImmutable(\sprintf('-%d days', $days)));
        }
    }
}
