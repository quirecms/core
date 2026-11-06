<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Quire\User\CurrentUser;
use Quire\User\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * "Confirm it's you" (D-42): before someone changes another person's access, runs new code (an extension,
 * a theme) or rewrites settings wholesale, they show again that they're the person signed in, by their
 * password or, without one (single sign-on), by signing in again. It holds for a few minutes, as GitHub's
 * "sudo mode" does, so a session left open or taken over isn't enough on its own.
 *
 * Signing in counts as confirming. Outside a request (the command line) there's nothing to confirm.
 *
 * @api
 */
final readonly class Confirmation
{
    /** How long a confirmation holds, in seconds */
    public const int LIFETIME = 600;

    /** What actions say when they need it; the admin shows the way to confirm with it */
    public const string REFUSAL = 'Confirm it\'s you first: this changes who may do what on the site.';

    private const string SESSION_KEY = 'quire.confirmed_at';

    public function __construct(
        private RequestStack $requests,
        private CurrentUser $users,
        private PasswordHasherFactoryInterface $hashers,
        private FormThrottle $throttle,
    ) {
    }

    /**
     * Whether the person signed in confirmed it's them in the last few minutes. True outside a request.
     */
    public function isFresh(): bool
    {
        $request = $this->requests->getMainRequest();

        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return true;
        }

        if (!$request->hasSession()) {
            return false;
        }

        $at = $request->getSession()->get(self::SESSION_KEY);

        return \is_int($at) && $at > time() - self::LIFETIME && $at <= time();
    }

    /**
     * Null when fresh, otherwise {@see self::REFUSAL}: for actions' denied().
     */
    public function refusal(): ?string
    {
        return $this->isFresh() ? null : self::REFUSAL;
    }

    /**
     * Whether the person signed in can confirm with a password; without one, they sign in again.
     */
    public function byPassword(): bool
    {
        $user = $this->users->get();

        return $user instanceof User && null !== $user->passwordHash;
    }

    /**
     * Checks the password of the person signed in, and on success holds the confirmation. Counted like
     * the sign-in form's attempts, so it can't be used to guess.
     */
    public function confirm(#[\SensitiveParameter] string $password): bool
    {
        $request = $this->requests->getMainRequest();
        $user = $this->users->get();

        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || !$user instanceof User || null === $user->passwordHash || !$this->throttle->allows('confirm|'.$user->id, $request)) {
            return false;
        }

        if (!$this->hashers->getPasswordHasher(User::class)->verify($user->passwordHash, $password)) {
            return false;
        }

        $this->hold();

        return true;
    }

    /**
     * Signing in is confirming: by password, a link or single sign-on alike. Not coming back with the
     * "remember me" cookie: that's the session taken along, not the person showing it's them.
     */
    #[AsEventListener]
    public function signedIn(LoginSuccessEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof RememberMeAuthenticator && $event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->set(self::SESSION_KEY, time());
        }
    }

    private function hold(): void
    {
        $this->requests->getMainRequest()?->getSession()->set(self::SESSION_KEY, time());
    }
}
