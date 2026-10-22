<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Psr\Container\ContainerInterface;
use Quire\Extend\Auth;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The authenticators of extensions (Extend\Auth), as one authenticator that a firewall lists once:
 *
 *     firewalls:
 *         main:
 *             custom_authenticators: [Quire\User\Security\ExtensionAuthenticators]
 *
 * For each request, it hands over to the first of them that supports it.
 *
 * @api
 */
final class ExtensionAuthenticators implements InteractiveAuthenticatorInterface, ResetInterface
{
    private ?AuthenticatorInterface $current = null;

    /**
     * @param list<array{class: string, owner: string}> $authenticators
     * @param ContainerInterface                        $services       The authenticators, by class, set by {@see \Quire\Permission\Internal\PermissionsPass}
     */
    public function __construct(
        #[Autowire(param: Auth::PARAMETER)]
        private readonly array $authenticators,
        private readonly ContainerInterface $services,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $this->current = null;

        foreach ($this->authenticators as $authenticator) {
            $service = $this->services->get($authenticator['class']);
            \assert($service instanceof AuthenticatorInterface);
            $supports = $service->supports($request);

            if (false !== $supports) {
                $this->current = $service;

                return $supports;
            }
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        return $this->current()->authenticate($request);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return $this->current()->createToken($passport, $firewallName);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->current()->onAuthenticationSuccess($request, $token, $firewallName);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->current()->onAuthenticationFailure($request, $exception);
    }

    public function isInteractive(): bool
    {
        return $this->current instanceof InteractiveAuthenticatorInterface && $this->current->isInteractive();
    }

    public function reset(): void
    {
        $this->current = null;
    }

    private function current(): AuthenticatorInterface
    {
        return $this->current ?? throw new \LogicException('No authenticator of an extension supports the request.');
    }
}
