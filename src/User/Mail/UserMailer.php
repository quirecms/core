<?php

declare(strict_types=1);

namespace Quire\User\Mail;

use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The emails Quire sends users: a link to reset their password, a link to confirm their email, and a
 * note that they already have an account. Themes can replace the templates in templates/email/. They
 * come from the setting "quire.mail.from", or from noreply@ the site's domain.
 *
 * Links point at the site's configured address ("framework.router.default_uri"), not at whatever host
 * the request named, so a forged Host header can't send someone's link to another site. Without that
 * address, the request's host is used only when it's checked against "framework.trusted_hosts".
 *
 * @internal
 */
final readonly class UserMailer
{
    public const string RESET_LIFETIME = 'PT1H';

    public const string VERIFICATION_LIFETIME = 'P2D';

    public const string INVITATION_LIFETIME = 'P7D';

    public function __construct(
        private Settings $settings,
        private RouterInterface $router,
        private RequestStack $requests,
        private TranslatorInterface $translator,
        #[Autowire(param: 'router.request_context.base_url')]
        private string $siteUri,
        private ?MailerInterface $mailer = null,
    ) {
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $this->send($user, 'password_reset', 'Reset your password', [
            'url' => $this->link('quire_reset_password', ['token' => $token]),
        ]);
    }

    /**
     * For a user an admin added: a link to choose their password, so nobody else ever knows it.
     */
    public function sendInvitation(User $user, string $token, ?User $invitedBy = null): void
    {
        $this->send($user, 'invitation', 'You\'re invited to {site}', [
            'url' => $this->link('quire_welcome', ['token' => $token]),
            'invited_by' => $invitedBy,
        ]);
    }

    public function sendVerification(User $user, string $token): void
    {
        $this->send($user, 'verify_email', 'Confirm your email address', [
            'url' => $this->link('quire_verify_email', ['token' => $token]),
        ]);
    }

    /**
     * For someone who tried to register with an email that has an account: the owner learns of it, and
     * the site doesn't tell the person registering whether the email has an account.
     */
    public function sendAccountExists(User $user): void
    {
        $this->send($user, 'account_exists', 'You already have an account', [
            'login_url' => $this->link('quire_login'),
            'reset_url' => $this->settings->getBool(AccountSettings::PASSWORD_RESET) ? $this->link('quire_forgot_password') : null,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(User $user, string $template, string $subject, array $context): void
    {
        if (!$this->mailer instanceof MailerInterface) {
            throw new \LogicException('Sending emails needs Symfony Mailer: set "framework.mailer.dsn".');
        }

        if (null === $user->email) {
            return;
        }

        $email = new TemplatedEmail()
            ->to(new Address($user->email, $user->displayName))
            ->subject($this->translator->trans($subject, ['site' => $this->settings->getString('site.title')], 'quire'))
            ->htmlTemplate(\sprintf('email/%s.html.twig', $template))
            ->textTemplate(\sprintf('email/%s.txt.twig', $template))
            ->context([...$context, 'user' => $user]);

        $this->mailer->send($email->from($this->sender()));
    }

    /**
     * The setting "quire.mail.from", or "<site title> <noreply@the site's domain>".
     */
    private function sender(): Address
    {
        $from = $this->settings->get(AccountSettings::MAIL_FROM);

        if (\is_string($from) && '' !== $from) {
            return Address::create($from);
        }

        $host = parse_url($this->link('quire_home'), \PHP_URL_HOST);

        return new Address('noreply@'.(\is_string($host) ? preg_replace('/^www\./', '', $host) : 'localhost'), $this->settings->getString('site.title'));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function link(string $route, array $parameters = []): string
    {
        $request = $this->requests->getMainRequest();

        $context = match (true) {
            '' !== $this->siteUri => RequestContext::fromUri($this->siteUri),
            $request instanceof Request && [] !== Request::getTrustedHosts() => new RequestContext()->fromRequest($request),
            default => throw new \LogicException('Emails link to the site, so set its address with "framework.router.default_uri" (DEFAULT_URI in .env), or trust only its hosts with "framework.trusted_hosts".'),
        };

        $previous = $this->router->getContext();
        $this->router->setContext($context);

        try {
            return $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($previous);
        }
    }
}
