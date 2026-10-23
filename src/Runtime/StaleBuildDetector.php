<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Doctrine\DBAL\Connection;
use Quire\Extension\Boot\CompileTimeState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Notices when the enabled extensions changed since this machine's application was built (e.g. an
 * admin enabled one on another server) and starts a refresh in the background.
 *
 * It checks at most once per interval per worker: one small query every few seconds. Polling is
 * deliberate: unlike LISTEN/NOTIFY it can't miss a change made while this machine was down, needs
 * no extra long-running process, and works behind connection poolers such as PgBouncer.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
final class StaleBuildDetector
{
    private int $nextCheckAt = 0;

    /**
     * @param string|null $builtForHash Fingerprint of the enabled extensions and the theme the build was made for; null when pinned
     * @param int         $interval     Seconds between checks; 0 disables checking
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly BackgroundRefresher $refresher,
        #[Autowire(param: 'quire.extensions.state_hash')]
        private readonly ?string $builtForHash,
        #[Autowire(param: 'quire.extensions.check_interval')]
        private readonly int $interval,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Whether the enabled extensions or the theme changed since this build. False when the database
     * can't be reached: carrying on with the current build is the best option then.
     */
    public function isStale(): bool
    {
        if (null === $this->builtForHash) {
            return false;
        }

        try {
            /** @var list<string> $enabledIds */
            $enabledIds = $this->connection->fetchFirstColumn('SELECT id FROM quire.extension WHERE enabled ORDER BY id');
            $activeTheme = CompileTimeState::activeThemeIn($this->connection);
        } catch (\Throwable) {
            return false;
        }

        return CompileTimeState::hashOf($enabledIds, $activeTheme) !== $this->builtForHash;
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->builtForHash || $this->interval <= 0) {
            return;
        }

        $now = $this->clock instanceof \Closure ? ($this->clock)() : time();
        \assert(\is_int($now));

        if ($now < $this->nextCheckAt) {
            return;
        }

        $this->nextCheckAt = $now + $this->interval;

        if ($this->isStale()) {
            $this->refresher->trigger();
        }
    }
}
