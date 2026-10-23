<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Psr\Log\LoggerInterface;
use Quire\Content\Permalink\Permalinks;
use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\CreateUser;
use Quire\User\Event\UserRegistered;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Mail\UserMailer;
use Quire\User\Role;
use Quire\User\Security\FormThrottle;
use Quire\User\Token\UserTokens;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\SecurityBundle\Security;
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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * Creating an account, and confirming its email. Off unless the settings "quire.auth.registration" and
 * "quire.auth.email_verification" turn them on.
 *
 * @internal
 */
#[AsController]
final readonly class RegistrationController
{
    public const string REGISTER_ROUTE = 'quire_register';

    public const string VERIFY_ROUTE = 'quire_verify_email';

    public const string RESEND_ROUTE = 'quire_resend_verification';

    public function __construct(
        private Environment $twig,
        private Settings $settings,
        private UserRepository $users,
        private UserWriter $writer,
        private UserTokens $tokens,
        private UserMailer $mailer,
        private FormThrottle $throttle,
        private CsrfTokenManagerInterface $csrf,
        private Security $security,
        private EventDispatcherInterface $dispatcher,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function register(Request $request): Response
    {
        if (!$this->settings->getBool(AccountSettings::REGISTRATION)) {
            throw new NotFoundHttpException('Registration is turned off.');
        }

        if ($this->security->getUser() instanceof User) {
            return new RedirectResponse($this->urls->generate(Permalinks::HOME_ROUTE));
        }

        $values = ['name' => '', 'email' => ''];

        if (!$request->isMethod('POST')) {
            return $this->render('security/register.html.twig', ['values' => $values, 'errors' => []]);
        }

        $values = ['name' => trim($request->request->getString('name')), 'email' => trim($request->request->getString('email'))];
        $refusal = $this->refusal($request, 'register');

        if (null !== $refusal) {
            return $this->render('security/register.html.twig', ['values' => $values, 'errors' => ['form' => $refusal]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $verification = $this->settings->getBool(AccountSettings::EMAIL_VERIFICATION);

        // Bots fill in every field, people don't see this one: tell them it worked.
        if ('' !== $request->request->getString('website')) {
            return $this->render('security/check_inbox.html.twig', ['email' => $values['email']]);
        }

        if ('' === $values['email']) {
            return $this->render('security/register.html.twig', ['values' => $values, 'errors' => ['email' => 'Enter your email address.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->users->findByEmail($values['email']);

        // With confirmation, the page doesn't tell whether the email has an account: its owner gets an
        // email about it instead.
        if ($existing instanceof User && $verification) {
            $this->attempt(fn () => $this->mailer->sendAccountExists($existing));

            return $this->render('security/check_inbox.html.twig', ['email' => $values['email']]);
        }

        try {
            $user = $this->writer->create(new CreateUser(
                '' === $values['name'] ? explode('@', $values['email'])[0] : $values['name'],
                $values['email'],
                password: $request->request->getString('password'),
                roles: [Role::MEMBER],
                emailVerified: false,
            ));
        } catch (InvalidUserException $e) {
            $errors = $e->violations;

            if (isset($errors['displayName'])) {
                $errors['name'] = $errors['displayName'];
            }

            // One message to translate, instead of one with the address in it.
            if (isset($errors['email']) && 'Another user has this email.' !== $errors['email']) {
                $errors['email'] = 'Enter a valid email address.';
            }

            return $this->render('security/register.html.twig', ['values' => $values, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->dispatcher->dispatch(new UserRegistered($user, $request));

        if ($verification) {
            $this->sendVerification($user);

            return $this->render('security/check_inbox.html.twig', ['email' => $values['email']]);
        }

        try {
            $this->security->login($user, 'form_login');
        } catch (\LogicException) {
            // A firewall without Quire's form login: they sign in on the sign-in page.
            return new RedirectResponse($this->urls->generate(LoginController::LOGIN_ROUTE));
        }

        return new RedirectResponse($this->urls->generate(Permalinks::HOME_ROUTE));
    }

    /**
     * Opened from the emailed link. Mail scanners that open links ahead of people can't do harm here:
     * confirming is all it does.
     */
    public function verify(Request $request, string $token): Response
    {
        $user = $this->tokens->consume($token, UserTokens::EMAIL_VERIFICATION);

        if (!$user instanceof User) {
            return $this->render('security/link_expired.html.twig', ['retry_route' => self::RESEND_ROUTE], Response::HTTP_GONE);
        }

        $this->writer->markEmailVerified($user->id);
        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('quire', 'Your email address is confirmed. You can sign in now.');
        }

        return new RedirectResponse($this->urls->generate($this->security->getUser() instanceof User ? Permalinks::HOME_ROUTE : LoginController::LOGIN_ROUTE));
    }

    /**
     * A new link to confirm the email, for those who lost the first one. The answer is the same whether
     * the email has an account or not.
     */
    public function resend(Request $request): Response
    {
        if (!$this->settings->getBool(AccountSettings::EMAIL_VERIFICATION)) {
            throw new NotFoundHttpException('Email confirmation is turned off.');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('security/resend_verification.html.twig', ['email' => '', 'errors' => []]);
        }

        $email = trim($request->request->getString('email'));
        $refusal = $this->refusal($request, 'resend_verification');

        if (null !== $refusal) {
            return $this->render('security/resend_verification.html.twig', ['email' => $email, 'errors' => ['form' => $refusal]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = '' === $email ? null : $this->users->findByEmail($email);

        if ($user instanceof User && $user->isActive() && !$user->hasVerifiedEmail() && !$this->tokens->issuedSince($user, UserTokens::EMAIL_VERIFICATION, new \DateTimeImmutable('-2 minutes'))) {
            $this->sendVerification($user);
        }

        return $this->render('security/check_inbox.html.twig', ['email' => $email]);
    }

    private function sendVerification(User $user): void
    {
        $token = $this->tokens->issue($user, UserTokens::EMAIL_VERIFICATION, new \DateInterval(UserMailer::VERIFICATION_LIFETIME));
        $this->attempt(fn () => $this->mailer->sendVerification($user, $token));
    }

    /**
     * Why the submission isn't accepted, if it isn't.
     */
    private function refusal(Request $request, string $form): ?string
    {
        return match (true) {
            !$this->csrf->isTokenValid(new CsrfToken($form, $request->request->getString('_csrf_token'))) => 'The form expired. Please try again.',
            !$this->throttle->allows($form, $request) => 'Too many attempts. Please try again in a few minutes.',
            default => null,
        };
    }

    /**
     * Sends an email; a failure is logged, and the page answers as usual, so it gives nothing away.
     */
    private function attempt(\Closure $send): void
    {
        try {
            $send();
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Quire couldn\'t send an email to a user: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
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
