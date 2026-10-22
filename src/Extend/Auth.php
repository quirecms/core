<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;

/**
 * Adds ways to sign in, as Symfony authenticators, e.g. single sign-on with an identity provider.
 *
 *     new Extend\Auth()
 *         ->authenticator(Sso\OidcAuthenticator::class)
 *
 * They're used by the firewall that lists Quire's authenticator, which a Quire site's security.yaml
 * does; extensions don't edit security.yaml. Show a link to them on the sign-in page with the
 * "auth.login_options" slot, and link the identities they sign in to users with
 * UserWriter::linkIdentity().
 *
 * @api
 */
final class Auth implements ExtenderInterface
{
    /** @internal Parameter: list<{class, owner}> */
    public const string PARAMETER = 'quire.auth.authenticators';

    /** @var list<string> */
    private array $authenticators = [];

    /**
     * @param class-string<AuthenticatorInterface> $class A service; autowired when not registered otherwise
     */
    public function authenticator(string $class): self
    {
        $this->authenticators[] = $class;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->authenticators as $class) {
            if (!is_a($class, AuthenticatorInterface::class, true)) {
                throw $context->error(\sprintf('Extend\Auth::authenticator("%s")', $class), \sprintf('Authenticators implement %s.', AuthenticatorInterface::class));
            }

            $context->appendToParameter(self::PARAMETER, ['class' => $class, 'owner' => $context->id]);
        }
    }
}
