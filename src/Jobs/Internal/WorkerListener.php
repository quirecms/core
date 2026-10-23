<?php

declare(strict_types=1);

namespace Quire\Jobs\Internal;

use Psr\Log\LoggerInterface;
use Quire\Jobs\Job;
use Quire\Runtime\BackgroundRefresher;
use Quire\Runtime\StaleBuildDetector;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * What a worker of Quire's jobs (`messenger:consume quire`) does besides handling them, every few
 * seconds: it reports that it's alive, dispatches the scheduled tasks that are due, and notices when
 * the enabled extensions changed. Then it rebuilds the application and stops, so that its process
 * manager (e.g. Docker's restart policy) starts it again with the new extensions' handlers.
 *
 * @internal
 */
final class WorkerListener
{
    /** Seconds between ticks */
    public const int INTERVAL = 15;

    private ?string $id = null;

    private int $nextTickAt = 0;

    /**
     * @param \Closure(): int|null $clock
     */
    public function __construct(
        private readonly Heartbeats $heartbeats,
        private readonly Scheduler $scheduler,
        private readonly StaleBuildDetector $staleBuild,
        private readonly BackgroundRefresher $refresher,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        if (!\in_array(Job::TRANSPORT, $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        $this->id = bin2hex(random_bytes(8));
        $this->nextTickAt = 0;
        $this->tick($event->getWorker());
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if (null !== $this->id) {
            $this->tick($event->getWorker());
        }
    }

    #[AsEventListener(event: WorkerStoppedEvent::class)]
    public function onStopped(): void
    {
        if (null === $this->id) {
            return;
        }

        try {
            $this->heartbeats->stop($this->id);
        } catch (\Throwable) {
            // It's forgotten after a day anyway.
        }

        $this->id = null;
    }

    private function tick(Worker $worker): void
    {
        $now = $this->clock instanceof \Closure ? ($this->clock)() : time();

        if (null === $this->id || $now < $this->nextTickAt) {
            return;
        }

        $this->nextTickAt = $now + self::INTERVAL;

        try {
            $this->heartbeats->beat($this->id, array_values(array_filter($worker->getMetadata()->getTransportNames(), \is_string(...))));
            $this->scheduler->run(\sprintf('%s:%d', Heartbeats::host(), Heartbeats::pid()));
        } catch (\Throwable $e) {
            // E.g. the database is unreachable for a moment: the worker carries on, and tries again.
            $this->logger?->warning('Quire couldn\'t run the scheduled tasks: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }

        if ($this->staleBuild->isStale()) {
            $this->logger?->notice('The enabled extensions changed. Quire rebuilds the application, and the worker stops to start with it.');
            $this->refresher->refreshNow();
            $worker->stop();
        }
    }
}
