<?php

declare(strict_types=1);

namespace Quire\User\Mail;

use Quire\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Whether the site can send e-mails, for admin interfaces: whether the transport it uses sends
 * ("framework.mailer", usually MAILER_DSN, set by whoever runs the site, which holds a secret and is
 * never shown), and a test e-mail to the person asking.
 *
 * @api
 */
final readonly class MailSetup
{
    /** @internal Parameter: the DSNs of the site's mail transports */
    public const string TRANSPORTS_PARAMETER = 'quire.mail.transports';

    /**
     * @param list<string> $transports
     */
    public function __construct(
        private UserMailer $mailer,
        #[Autowire(param: self::TRANSPORTS_PARAMETER)]
        private array $transports = [],
    ) {
    }

    /**
     * Whether a transport that sends is set: not none, and not "null://" (which drops every e-mail).
     */
    public function configured(): bool
    {
        return array_any($this->transports, self::sends(...));
    }

    /**
     * Whether a transport's DSN sends, from its parsed scheme: "failover(…)" and "roundrobin(…)" do when
     * one of theirs does.
     *
     * @internal
     */
    public static function sends(string $dsn): bool
    {
        $dsn = trim($dsn);

        if (1 === preg_match('/^(?:failover|roundrobin)\((.*)\)$/s', $dsn, $match)) {
            $inners = preg_split('/\s+/', trim($match[1]));

            return false !== $inners && array_any($inners, static fn (string $inner): bool => self::sends($inner));
        }

        try {
            return 'null' !== Dsn::fromString($dsn)->getScheme();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sends the test e-mail to the user's own address.
     *
     * @throws \LogicException when they have no confirmed address
     */
    public function sendTest(User $user): void
    {
        if (!$user->hasVerifiedEmail()) {
            throw new \LogicException('A test e-mail goes only to your own confirmed address: confirm your e-mail address first.');
        }

        $this->mailer->sendTest($user);
    }
}
