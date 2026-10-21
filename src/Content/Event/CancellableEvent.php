<?php

declare(strict_types=1);

namespace Quire\Content\Event;

/**
 * An event dispatched before a change is saved. Listeners can cancel the change, and the code that
 * requested it gets a {@see \Quire\Content\Exception\ContentOperationCancelledException} with the reason.
 *
 * These events are dispatched inside the database transaction of the change, so what listeners write
 * to the database is saved or rolled back together with it.
 *
 * @api
 */
abstract class CancellableEvent
{
    private ?string $cancelReason = null;

    /**
     * @param string $reason Shown to the person who requested the change
     */
    public function cancel(string $reason): void
    {
        $this->cancelReason = $reason;
    }

    public function isCancelled(): bool
    {
        return null !== $this->cancelReason;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }
}
