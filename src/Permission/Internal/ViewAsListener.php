<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Quire\Permission\ViewAs;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runs each request as the user someone is seeing the site as (see {@see ViewAs}), and refuses requests
 * that would change something. The swap lasts for the request only: the session keeps the viewer's own
 * sign-in, so stopping, or a problem, always leaves them as themselves.
 *
 * @internal
 */
final class ViewAsListener implements ResetInterface
{
    private ?TokenInterface $original = null;

    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly TokenStorageInterface $tokens,
        private readonly UserRepository $users,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewalls,
    ) {
    }

    /** After the firewall (8), which signs the viewer in from the session */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $ids = $this->viewAs->ids();
        $token = null === $ids ? null : $this->tokens->getToken();

        // Only for the viewer who started it, still signed in.
        if (null === $ids || !$token instanceof TokenInterface || $token->getUserIdentifier() !== $this->users->find($ids['viewer'])?->getUserIdentifier()) {
            return;
        }

        $target = $this->users->find($ids['target']);

        if (!$target instanceof \Quire\User\User || !$target->isActive()) {
            $this->viewAs->stop();

            return;
        }

        $request = $event->getRequest();
        $firewall = $this->firewalls->getFirewallConfig($request)?->getName() ?? 'main';
        $this->original = $token;
        $this->tokens->setToken(new SwitchUserToken($target, $firewall, $target->getRoles(), $token));

        if (!$request->isMethodSafe() && true !== $request->attributes->get(ViewAs::ALLOWED_ROUTE)) {
            throw new AccessDeniedHttpException(\sprintf('You\'re seeing the site as %s, so nothing can be changed. Stop seeing it as them first.', $target->displayName));
        }
    }

    /** Before the session stores the sign-in (the firewall's context listener, at 0) */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 64)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->original instanceof TokenInterface) {
            $this->tokens->setToken($this->original);
            $this->original = null;
        }
    }

    public function reset(): void
    {
        $this->original = null;
    }
}
