<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Quire\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Signing in with a password takes the same work whether the account exists or not (D-44): the password
 * typed for an account that doesn't exist, or has no password (single sign-on only), is checked against
 * a dummy hash made with the site's own hasher settings. Without it, the answer came back without
 * hashing for those, many times faster than for a real account with a wrong password. The dummy hash is
 * kept in the cache: made in the request, it would make the first such answer slower instead.
 *
 * As Spring Security and Django do. A fix in the frozen core (D-29): identity moves to YorvoID, whose
 * flows have their own equal-work rule.
 *
 * @internal
 */
final readonly class EqualWorkSignIn
{
    private const string CACHE_KEY = 'quire.sign_in.dummy_hash';

    public function __construct(
        private PasswordHasherFactoryInterface $hashers,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * After the setting that turns passwords off and the CSRF check (512), before the first listener that
     * loads the user (Symfony's user checker, 256: a missing one throws there) and the credentials check (0).
     */
    #[AsEventListener(priority: 300)]
    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $badge = $passport->getBadge(PasswordCredentials::class);

        // An empty password is refused before any hashing, for every account alike.
        if (!$badge instanceof PasswordCredentials || $badge->isResolved() || '' === $badge->getPassword()) {
            return;
        }

        try {
            $user = $passport->getUser();
        } catch (UserNotFoundException $e) {
            $this->checkDummy($badge->getPassword());

            throw $e;
        }

        // Symfony refuses an account without a password without hashing: the check is done here instead.
        if ($user instanceof PasswordAuthenticatedUserInterface && null === $user->getPassword()) {
            $this->checkDummy($badge->getPassword());
        }
    }

    private function checkDummy(#[\SensitiveParameter] string $password): void
    {
        $hasher = $this->hashers->getPasswordHasher(User::class);
        $make = static fn (): string => $hasher->hash(bin2hex(random_bytes(16)));
        $hash = $this->cache->get(self::CACHE_KEY, $make);

        // Made with other settings than the site's now (a stronger cost): made again, once.
        if ($hasher->needsRehash($hash)) {
            $this->cache->delete(self::CACHE_KEY);
            $hash = $this->cache->get(self::CACHE_KEY, $make);
        }

        $hasher->verify($hash, $password);
    }
}
