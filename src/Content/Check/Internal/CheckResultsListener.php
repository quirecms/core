<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use Psr\Log\LoggerInterface;
use Quire\Content\Check\CheckResults;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Job\RecheckContent;
use Quire\Content\Check\Job\RecheckLinkingContent;
use Quire\Content\Content;
use Quire\Content\Event\ContentCreated;
use Quire\Content\Event\ContentDeleted;
use Quire\Content\Event\ContentRestored;
use Quire\Content\Event\ContentStatusChanged;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Status;
use Quire\Settings\Event\SettingChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the stored check results ({@see CheckResults}) up to date: an item is checked when it's saved,
 * and the items that may link to it when visitors start or stop seeing it, or it moves. Links only lead
 * to what visitors see, so other changes don't matter to them. Those items, and all items when the site
 * turns a check on, are checked in the background.
 *
 * @internal
 */
final readonly class CheckResultsListener
{
    public function __construct(
        private CheckResults $results,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onCreated(ContentCreated $event): void
    {
        $this->refresh($event->content);
    }

    #[AsEventListener]
    public function onUpdated(ContentUpdated $event): void
    {
        $this->refresh($event->content);

        if ($event->before->slug !== $event->content->slug && ($event->before->isPublic() || $event->content->isPublic())) {
            $this->recheckLinking($event->content, $event->before->slug);
        }
    }

    #[AsEventListener]
    public function onStatusChanged(ContentStatusChanged $event): void
    {
        $this->refresh($event->content);

        if ($event->before->isPublic() !== $event->content->isPublic()) {
            $this->recheckLinking($event->content);
        }
    }

    #[AsEventListener]
    public function onRestored(ContentRestored $event): void
    {
        $this->refresh($event->content);

        if ($event->content->isPublic()) {
            $this->recheckLinking($event->content);
        }
    }

    #[AsEventListener]
    public function onTrashed(ContentDeleted $event): void
    {
        // The trashed item: whether visitors saw it before is in its status and date.
        if (Status::PUBLISHED === $event->content->status || (Status::SCHEDULED === $event->content->status && $event->content->publishedAt instanceof \DateTimeImmutable && $event->content->publishedAt <= new \DateTimeImmutable())) {
            $this->recheckLinking($event->content);
        }
    }

    #[AsEventListener]
    public function onSettingChanged(SettingChanged $event): void
    {
        // Levels apply when results are read; only a check turned on needs running.
        if (ContentChecker::LEVELS_SETTING !== $event->key) {
            return;
        }

        $old = \is_array($event->oldValue) ? $event->oldValue : [];
        $new = \is_array($event->newValue) ? $event->newValue : [];

        foreach ($old as $check => $level) {
            if ('off' === $level && 'off' !== ($new[$check] ?? null)) {
                $this->bus->dispatch(new RecheckContent());

                return;
            }
        }
    }

    private function refresh(Content $item): void
    {
        // The content was saved: a check that fails mustn't make it look as if it wasn't. The nightly
        // task checks it again.
        try {
            $this->results->refresh($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Checking the content item {id} failed: {message}', ['id' => $item->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function recheckLinking(Content $item, ?string $previousSlug = null): void
    {
        $this->bus->dispatch(new RecheckLinkingContent(null === $previousSlug ? [$item->slug] : [$item->slug, $previousSlug], $item->id));
    }
}
