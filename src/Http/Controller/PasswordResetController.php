<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Mail\UserMailer;
use Quire\User\Security\FormThrottle;
use Quire\User\Security\SignInListener;
use Quire\User\Token\UserTokens;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Choosing a new password through an emailed link. Off unless the setting "quire.auth.password_reset"
 * turns it on, and while signing in with a password is turned off.
 *
 * @internal
 */
#[AsController]
final readonly class PasswordResetController
{
    public const string FORGOT_ROUTE = 'quire_forgot_password';

    public const string RESET_ROUTE = 'quire_reset_password';

    private const string SESSION_TOKEN = 'quire.password_reset_token';

    public function __construct(
        private Environment $twig,
        private Settings $settings,
        private UserRepository $users,
        private UserWriter $writer,
        private UserTokens $tokens,
        private UserMailer $mailer,
        private FormThrottle $throttle,
        private CsrfTokenManagerInterface $csrf,
        private Connection $connection,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Asks for the email to send the link to. The answer is the same whether the email has an account or
     * not, so the page can't be used to find out who has one.
     */
    public function forgot(Request $request): Response
    {
        $this->assertOn();

        if (!$request->isMethod('POST')) {
            return $this->render('security/forgot_password.html.twig', ['email' => '', 'errors' => []]);
        }

        $email = trim($request->request->getString('email'));
        $refusal = match (true) {
            !$this->csrf->isTokenValid(new CsrfToken('forgot_password', $request->request->getString('_csrf_token'))) => 'The form expired. Please try again.',
            !$this->throttle->allows('forgot_password', $request) => 'Too many attempts. Please try again in a few minutes.',
            default => null,
        };

        if (null !== $refusal) {
            return $this->render('security/forgot_password.html.twig', ['email' => $email, 'errors' => ['form' => $refusal]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = '' === $email ? null : $this->users->findByEmail($email);

        // At most one email every 2 minutes per account, so the form can't flood an inbox.
        if ($user instanceof User && $user->isActive() && !$this->tokens->issuedSince($user, UserTokens::PASSWORD_RESET, new \DateTimeImmutable('-2 minutes'))) {
            $token = $this->tokens->issue($user, UserTokens::PASSWORD_RESET, new \DateInterval(UserMailer::RESET_LIFETIME));

            try {
                $this->mailer->sendPasswordReset($user, $token);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Quire couldn\'t send an email to a user: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            }
        }

        return $this->render('security/check_inbox.html.twig', ['email' => $email]);
    }

    /**
     * The emailed link leads here with the token, which moves into the session at once: the page with
     * the form then has no token in its address, so it can't leak through history or Referer headers.
     */
    public function reset(Request $request, ?string $token = null): Response
    {
        $this->assertOn();
        $session = $request->getSession();

        if (null !== $token) {
            $session->set(self::SESSION_TOKEN, $token);

            return new RedirectResponse($this->urls->generate(self::RESET_ROUTE));
        }

        $token = $session->get(self::SESSION_TOKEN);
        $user = \is_string($token) ? $this->tokens->check($token, UserTokens::PASSWORD_RESET) : null;

        if (!\is_string($token) || !$user instanceof User) {
            return $this->render('security/link_expired.html.twig', ['retry_route' => self::FORGOT_ROUTE], Response::HTTP_GONE);
        }

        if (!$request->isMethod('POST')) {
            return $this->render('security/reset_password.html.twig', ['user' => $user, 'errors' => []]);
        }

        if (!$this->csrf->isTokenValid(new CsrfToken('reset_password', $request->request->getString('_csrf_token')))) {
            return $this->render('security/reset_password.html.twig', ['user' => $user, 'errors' => ['form' => 'The form expired. Please try again.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // The token is used up only when the new password is accepted.
            $this->connection->transactional(function () use ($token, $request): void {
                $user = $this->tokens->consume($token, UserTokens::PASSWORD_RESET) ?? throw InvalidUserException::single('form', 'This link doesn\'t work anymore.');
                $this->writer->changePassword($user->id, $request->request->getString('password'));
                // Opening the link proved they read the email.
                $this->writer->markEmailVerified($user->id);
            });
        } catch (InvalidUserException $e) {
            return $this->render('security/reset_password.html.twig', ['user' => $user, 'errors' => $e->violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->remove(self::SESSION_TOKEN);
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('quire', 'Your password is changed. You can sign in with it now.');
        }

        return new RedirectResponse($this->urls->generate(LoginController::LOGIN_ROUTE));
    }

    private function assertOn(): void
    {
        if (!$this->settings->getBool(AccountSettings::PASSWORD_RESET) || !$this->settings->getBool(SignInListener::PASSWORD_LOGIN_SETTING)) {
            throw new NotFoundHttpException('Resetting passwords is turned off.');
        }
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables, int $status = Response::HTTP_OK): Response
    {
        return PrivatePage::secure(new Response($this->twig->render($template, $variables), $status));
    }
}
