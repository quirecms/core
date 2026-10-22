<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Content\Permalink\Permalinks;
use Quire\Routing\ExtensionRouteLoader;
use Quire\Settings\Settings;
use Quire\User\Event\UserLoggedIn;
use Quire\User\Event\UserLoggedOut;
use Quire\User\Event\UserLoggingIn;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Connects Symfony's sign-in to Quire, for Quire's users and whichever way they sign in: the setting that
 * turns off passwords, the UserLoggingIn/UserLoggedIn/UserLoggedOut events, and the time of the last
 * sign-in. It also keeps the redirect after signing in on Quire's sign-in page within the site.
 *
 * @internal
 */
final readonly class SignInListener
{
    public const string PASSWORD_LOGIN_SETTING = 'quire.auth.password_login';

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private Settings $settings,
        private UserWriter $writer,
        private Permalinks $permalinks,
        private RequestStack $requests,
        private Security $security,
    ) {
    }

    /**
     * Before the user is loaded and the password checked (which happen at priority 0 and lower).
     */
    #[AsEventListener(priority: 512)]
    public function refusePasswordsWhenTurnedOff(CheckPassportEvent $event): void
    {
        if ($event->getPassport()->hasBadge(PasswordCredentials::class) && !$this->settings->getBool(self::PASSWORD_LOGIN_SETTING)) {
            throw new CustomUserMessageAuthenticationException('Signing in with a password is turned off.');
        }
    }

    /**
     * After the user is loaded, before the credentials are checked.
     */
    #[AsEventListener(priority: 128)]
    public function askListeners(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();

        if (!$user instanceof User) {
            return;
        }

        // The passport doesn't carry the request: the one being authenticated is the current one.
        $request = $this->requests->getCurrentRequest() ?? new Request();
        $signingIn = new UserLoggingIn($user, $request, $this->security->getFirewallConfig($request)?->getName() ?? '');
        $this->dispatcher->dispatch($signingIn);

        if ($signingIn->isCancelled()) {
            throw new CustomUserMessageAuthenticationException((string) $signingIn->cancelReason());
        }
    }

    #[AsEventListener]
    public function signedIn(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User) {
            $this->writer->recordLogin($user->id);
            $this->dispatcher->dispatch(new UserLoggedIn($user, $event->getRequest(), $event->getFirewallName()));
        }

        $this->keepRedirectOnSite($event);
    }

    #[AsEventListener]
    public function signedOut(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        if ($user instanceof User) {
            $this->dispatcher->dispatch(new UserLoggedOut($user, $event->getRequest()));
        }
    }

    /**
     * Where to go after signing in can come from the request, so it must not lead to another site: that
     * would let a link to the sign-in page send people to a look-alike site.
     */
    private function keepRedirectOnSite(LoginSuccessEvent $event): void
    {
        $response = $event->getResponse();
        $request = $event->getRequest();

        if (!$response instanceof RedirectResponse || !$request->attributes->getBoolean(ExtensionRouteLoader::QUIRE_ROUTE)) {
            return;
        }

        $target = $response->getTargetUrl();
        $onSite = (str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\'))
            || str_starts_with($target, $request->getSchemeAndHttpHost().'/');

        if (!$onSite) {
            $event->setResponse(new RedirectResponse($this->permalinks->homeUrl()));
        }
    }
}
