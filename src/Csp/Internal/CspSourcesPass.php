<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Quire\Csp\Csp;
use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Who may add other sites' hosts to the policy (doc 20, condition C-C): `quire/privacy` through
 * {@see ThirdPartySourcesInterface}, and core's own parts through {@see FirstPartySourcesInterface}.
 * Anything else stops the container from being built, with the reason: a host added elsewhere would
 * load before the visitor's consent, and be missing from the privacy page.
 *
 * It runs once the container's services are final (after decoration, which replaces a service with
 * another class), and checks the classes actually bound: the slot, every tagged first-party service,
 * and every service handed to {@see Csp}. The guard of trusted shortcodes' embeds is checked the same
 * way ({@see \Quire\Formatter\Internal\EmbedGuardPass}).
 *
 * Extensions run PHP, so this can't stop one that means to get round it (a listener can rewrite the
 * header); it makes the rule hold for every extension that uses the container's API, and says so to
 * one that tries.
 *
 * @internal
 */
final readonly class CspSourcesPass extends PrivacySlotPass
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(ThirdPartySourcesInterface::class) || $container->hasDefinition(ThirdPartySourcesInterface::class)) {
            $this->assertThirdParty($container, $this->class($container, ThirdPartySourcesInterface::class));
        }

        foreach (array_keys($container->findTaggedServiceIds(FirstPartySourcesInterface::TAG)) as $id) {
            $this->assertFirstParty($container, $this->class($container, $id));
        }

        if (!$container->hasDefinition(Csp::class)) {
            return;
        }

        $csp = $container->getDefinition(Csp::class);
        $this->assertBuiltBy($container, $csp, Csp::class, $this->assertSource(...), 'The Content-Security-Policy service');

        // What the policy is actually built from, whatever ids the services have by now: services by
        // reference and inline ones alike (an extension's inline_service()), with what makes them.
        foreach ($this->definitions($container, $csp->getArguments()) as $definition) {
            $this->assertSource($container, $this->classOf($container, $definition));
            $this->assertBuiltBy($container, $definition, null, $this->assertSource(...), '');
        }
    }

    private function assertSource(ContainerBuilder $container, string $class): void
    {
        if (NoThirdPartySources::class === $class || $this->isFrom($container, $class, $this->core()) || $this->isPrivacy($container, $class)) {
            return;
        }

        $this->assertThirdParty($container, $class);
    }

    private function assertThirdParty(ContainerBuilder $container, string $class): void
    {
        if (NoThirdPartySources::class !== $class && !$this->isPrivacy($container, $class)) {
            throw new InvalidArgumentException(\sprintf('"%s" adds other sites\' hosts to the Content-Security-Policy. Only quire/privacy may (its services are what visitors are asked about): declare the service through quire/privacy instead. See docs/security.md.', $class));
        }
    }

    private function assertFirstParty(ContainerBuilder $container, string $class): void
    {
        if (!$this->isFrom($container, $class, $this->core())) {
            throw new InvalidArgumentException(\sprintf('"%s" adds sources to the Content-Security-Policy through an interface of Quire\'s own: only core\'s parts may. Other sites\' hosts come from quire/privacy\'s services, the site\'s from "quire.csp.sources". See docs/security.md.', $class));
        }
    }
}
