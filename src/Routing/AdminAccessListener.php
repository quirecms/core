<?php

declare(strict_types=1);

namespace Quire\Routing;

use Quire\Extend\Routes;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Lets only users with the ability "quire.admin.access" open the pages of the admin group (see
 * {@see Routes}), whichever extension added them. Guests are sent to sign in by the firewall, others
 * get "403 Forbidden".
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)] // After the firewall (8), which knows who signs in.
final readonly class AdminAccessListener
{
    public const string ABILITY = 'quire.admin.access';

    public function __construct(
        private ?Security $security = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (Routes::ADMIN !== $event->getRequest()->attributes->get(ExtensionRouteLoader::GROUP)) {
            return;
        }

        if (true !== $this->security?->isGranted(self::ABILITY)) {
            throw new AccessDeniedException('Only users who may use the admin interface can open this page.');
        }
    }
}
