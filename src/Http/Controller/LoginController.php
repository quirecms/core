<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Quire\Settings\Settings;
use Quire\User\Security\SignInListener;
use Quire\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * The sign-in page. Symfony's form login checks the submitted form before this runs, and the firewall
 * handles signing out; see docs/users.md for the firewall configuration.
 *
 * @internal
 */
#[AsController]
final readonly class LoginController
{
    public const string LOGIN_ROUTE = 'quire_login';

    public const string LOGOUT_ROUTE = 'quire_logout';

    public function __construct(
        private Environment $twig,
        private AuthenticationUtils $authentication,
        private Security $security,
        private Settings $settings,
    ) {
    }

    public function login(Request $request): Response
    {
        $user = $this->security->getUser();

        $response = new Response($this->twig->render('security/login.html.twig', [
            'user' => $user instanceof User ? $user : null,
            'error' => $this->authentication->getLastAuthenticationError(),
            'last_username' => $this->authentication->getLastUsername(),
            'password_login' => $this->settings->getBool(SignInListener::PASSWORD_LOGIN_SETTING),
            'target_path' => $this->onSitePath($request->query->getString('next')),
        ]));

        // Never cached, and never shown inside another site, where a frame could capture the password.
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    public function logout(): never
    {
        throw new \LogicException('The firewall signs users out before this runs. Set "logout: { path: quire_logout }" on the firewall in config/packages/security.yaml.');
    }

    /**
     * A path on this site to go to after signing in, e.g. "/drafts"; anything else, e.g. another site's
     * address, is ignored.
     */
    private function onSitePath(string $path): ?string
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\') ? $path : null;
    }
}
