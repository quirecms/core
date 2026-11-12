<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Composer\InstalledVersions;
use Quire\Csp\Csp;
use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
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
 * and every service handed to {@see Csp}.
 *
 * Extensions run PHP, so this can't stop one that means to get round it (a listener can rewrite the
 * header); it makes the rule hold for every extension that uses the container's API, and says so to
 * one that tries.
 *
 * @internal
 */
final readonly class CspSourcesPass implements CompilerPassInterface
{
    private const string PRIVACY = 'quire/privacy';

    private ?string $privacy;

    /**
     * @param string|null $privacyPath Where quire/privacy is installed; by default, where Composer put it
     */
    public function __construct(?string $privacyPath = null)
    {
        $privacyPath ??= InstalledVersions::isInstalled(self::PRIVACY) ? InstalledVersions::getInstallPath(self::PRIVACY) : null;
        $real = null === $privacyPath ? false : realpath($privacyPath);
        $this->privacy = false === $real ? null : $real.\DIRECTORY_SEPARATOR;
    }

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

        // What the policy is actually built from, whatever ids the services have by now.
        foreach ($this->references($container->getDefinition(Csp::class)->getArguments()) as $reference) {
            $id = (string) $reference;

            if (!$container->has($id)) {
                continue;
            }

            $class = $this->class($container, $id);

            if (NoThirdPartySources::class === $class || $this->isFrom($container, $class, $this->core()) || $this->isPrivacy($container, $class)) {
                continue;
            }

            $this->assertThirdParty($container, $class);
        }
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

    /**
     * quire/privacy's: its namespace, and a file of the package where Composer installed it.
     */
    private function isPrivacy(ContainerBuilder $container, string $class): bool
    {
        return str_starts_with($class, ThirdPartySourcesInterface::NAMESPACE) && null !== $this->privacy && $this->isFrom($container, $class, $this->privacy);
    }

    private function isFrom(ContainerBuilder $container, string $class, string $directory): bool
    {
        $file = $container->getReflectionClass($class, false)?->getFileName();
        $real = \is_string($file) ? realpath($file) : false;

        return \is_string($real) && str_starts_with($real, $directory);
    }

    private function core(): string
    {
        $core = realpath(\dirname(__DIR__, 2));

        return (false === $core ? \dirname(__DIR__, 2) : $core).\DIRECTORY_SEPARATOR;
    }

    /**
     * The services among a definition's arguments, also inside lists and iterators.
     *
     * @param array<mixed> $arguments
     *
     * @return iterable<Reference>
     */
    private function references(array $arguments): iterable
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Reference) {
                yield $argument;
            } elseif ($argument instanceof ArgumentInterface) {
                yield from $this->references($argument->getValues());
            } elseif (\is_array($argument)) {
                yield from $this->references($argument);
            }
        }
    }

    private function class(ContainerBuilder $container, string $id): string
    {
        $definition = $container->findDefinition($id);
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return \is_string($class) ? $class : $id;
    }
}
