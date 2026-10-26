<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Quire\Http\Controller\ViewAsController;
use Quire\Permission\ViewAs;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    private ?string $targetName = null;

    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly TokenStorageInterface $tokens,
        private readonly UserRepository $users,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewalls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
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
        $this->targetName = $target->displayName;
        $this->tokens->setToken(new SwitchUserToken($target, $firewall, $target->getRoles(), $token));

        if (!$request->isMethodSafe() && true !== $request->attributes->get(ViewAs::ALLOWED_ROUTE)) {
            throw new AccessDeniedHttpException(\sprintf('You\'re seeing the site as %s, so nothing can be changed. Stop seeing it as them first.', $target->displayName));
        }
    }

    /** Before the session stores the sign-in (the firewall's context listener, at 0) */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->original instanceof TokenInterface) {
            return;
        }

        $this->tokens->setToken($this->original);
        $this->original = null;

        // Every page says so, and offers to stop: the site's, the admin's, and error pages.
        if (null !== $this->viewAs->ids() && null !== $this->targetName) {
            $this->addBar($event->getRequest(), $event->getResponse(), $this->targetName);
        }

        $this->targetName = null;
    }

    private function addBar(Request $request, Response $response, string $name): void
    {
        $content = $response->getContent();
        $type = $response->headers->get('Content-Type', 'text/html');

        if (false === $content || $response->isRedirection() || !str_contains($type, 'html') || $request->headers->has('Turbo-Frame') || $request->isXmlHttpRequest()) {
            return;
        }

        // At the top of the page, where it covers nothing (the admin's menu is at the bottom on phones).
        if (1 !== preg_match('/<body\b[^>]*>/i', $content, $match, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        $position = $match[0][1] + \strlen($match[0][0]);

        $bar = \sprintf(
            '<div class="quire-view-as" role="region" aria-label="%1$s" style="position:relative;z-index:2147483000;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:center;padding:.6rem 1rem;background:#1d1a24;color:#fff;font:600 15px/1.4 system-ui,sans-serif">'
            .'<span>%2$s</span>'
            .'<form method="post" action="%3$s" style="margin:0"><input type="hidden" name="_token" value="%4$s"><input type="hidden" name="back" value="%5$s">'
            .'<button type="submit" style="min-height:2.25rem;padding:.3rem 1rem;border:0;border-radius:999px;background:#f0b04a;color:#1d1a24;font:inherit;cursor:pointer">%6$s</button></form></div>',
            htmlspecialchars($this->translator->trans('Seeing as someone else', [], 'quire')),
            htmlspecialchars($this->translator->trans('You’re seeing this as {name}: nothing can be changed.', ['name' => $name], 'quire')),
            htmlspecialchars($this->urls->generate(ViewAsController::STOP_ROUTE)),
            htmlspecialchars($this->csrf->getToken(ViewAsController::CSRF_TOKEN)->getValue()),
            htmlspecialchars($request->getRequestUri()),
            htmlspecialchars($this->translator->trans('Stop seeing as {name}', ['name' => $name], 'quire')),
        );
        $response->setContent(substr($content, 0, $position).$bar.substr($content, $position));
    }

    public function reset(): void
    {
        $this->original = null;
        $this->targetName = null;
    }
}
