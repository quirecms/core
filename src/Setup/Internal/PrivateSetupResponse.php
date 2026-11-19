<?php

declare(strict_types=1);

namespace Quire\Setup\Internal;

use Quire\Setup\SetupSteps;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A page that showed its viewer the setup steps is theirs alone: private, never stored. A visitor's
 * version of the same page is left as it is (default theme A1: no Vary on cookies).
 *
 * @internal
 */
final readonly class PrivateSetupResponse
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && true === $event->getRequest()->attributes->get(SetupSteps::PRIVATE_ATTRIBUTE)) {
            $event->getResponse()->setPrivate();
            $event->getResponse()->headers->addCacheControlDirective('no-store');
        }
    }
}
