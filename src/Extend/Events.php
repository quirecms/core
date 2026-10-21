<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to events: Quire's domain events, Symfony's kernel events, or other extensions' events.
 *
 *     (new Extend\Events())
 *         ->listen(ContentPublished::class, NotifySubscribers::class)
 *         ->subscribe(ReviewSubscriber::class);
 *
 * Listeners are services, so their dependencies are injected. Classes with `#[AsEventListener]`
 * registered through {@see Services::autoload()} work too.
 *
 * @api
 */
final class Events implements ExtenderInterface
{
    /** @var list<array{event: string, listener: string, method: string, priority: int}> */
    private array $listeners = [];

    /** @var list<string> */
    private array $subscribers = [];

    /**
     * @param class-string $event    The event class, or an event name for string-named events
     * @param class-string $listener A class with an __invoke() method, or with $method
     * @param int          $priority Higher runs earlier
     */
    public function listen(string $event, string $listener, string $method = '__invoke', int $priority = 0): self
    {
        $this->listeners[] = ['event' => $event, 'listener' => $listener, 'method' => $method, 'priority' => $priority];

        return $this;
    }

    /**
     * @param class-string<EventSubscriberInterface> $subscriber
     */
    public function subscribe(string $subscriber): self
    {
        $this->subscribers[] = $subscriber;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->listeners as $listener) {
            $where = \sprintf('Extend\Events::listen("%s")', $listener['event']);

            if (!class_exists($listener['listener'])) {
                throw $context->error($where, \sprintf('The listener class "%s" doesn\'t exist.', $listener['listener']));
            }

            if (!method_exists($listener['listener'], $listener['method'])) {
                throw $context->error($where, \sprintf('The listener "%s" has no method "%s".', $listener['listener'], $listener['method']));
            }

            $context->service($listener['listener'])->addTag('kernel.event_listener', [
                'event' => $listener['event'],
                'method' => $listener['method'],
                'priority' => $listener['priority'],
            ]);
        }

        foreach ($this->subscribers as $subscriber) {
            $where = \sprintf('Extend\Events::subscribe("%s")', $subscriber);

            if (!class_exists($subscriber)) {
                throw $context->error($where, 'The class doesn\'t exist.');
            }

            if (!is_subclass_of($subscriber, EventSubscriberInterface::class)) {
                throw $context->error($where, \sprintf('The class must implement %s.', EventSubscriberInterface::class));
            }

            $definition = $context->service($subscriber);

            if (!$definition->hasTag('kernel.event_subscriber')) {
                $definition->addTag('kernel.event_subscriber');
            }
        }
    }
}
