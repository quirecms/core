<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Composer\InstalledVersions;
use Quire\Csp\Csp;
use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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

        $csp = $container->getDefinition(Csp::class);
        $this->assertBuiltBy($container, $csp, Csp::class);

        // What the policy is actually built from, whatever ids the services have by now: services by
        // reference and inline ones alike (an extension's inline_service()), with what makes them.
        foreach ($this->definitions($container, $csp->getArguments()) as $definition) {
            $this->assertSource($container, $this->classOf($container, $definition));
            $this->assertBuiltBy($container, $definition, null);
        }
    }

    private function assertSource(ContainerBuilder $container, string $class): void
    {
        if (NoThirdPartySources::class === $class || $this->isFrom($container, $class, $this->core()) || $this->isPrivacy($container, $class)) {
            return;
        }

        $this->assertThirdParty($container, $class);
    }

    /**
     * A factory or configurator decides what object a service is, whatever its class says: theirs must
     * be core's or quire/privacy's too. The policy itself is core's Csp, made by nothing else.
     */
    private function assertBuiltBy(ContainerBuilder $container, Definition $definition, ?string $only): void
    {
        if (null !== $only && $only !== $this->classOf($container, $definition)) {
            throw new InvalidArgumentException(\sprintf('The Content-Security-Policy service is "%s", not Quire\'s "%s". Change the policy with "quire.csp" or through quire/privacy. See docs/security.md.', $this->classOf($container, $definition), $only));
        }

        foreach (['factory' => $definition->getFactory(), 'configurator' => $definition->getConfigurator()] as $what => $callable) {
            if (null === $callable) {
                continue;
            }

            $class = match (true) {
                \is_string($callable) => str_contains($callable, '::') ? explode('::', $callable)[0] : $callable,
                $callable[0] instanceof Reference => $container->has((string) $callable[0]) ? $this->class($container, (string) $callable[0]) : (string) $callable[0],
                $callable[0] instanceof Definition => $this->classOf($container, $callable[0]),
                \is_string($callable[0]) => $callable[0],
                default => '',
            };

            if (null !== $only) {
                throw new InvalidArgumentException(\sprintf('The Content-Security-Policy service has a %s ("%s"): Quire\'s is made by nothing else. See docs/security.md.', $what, $class));
            }

            $this->assertSource($container, $class);
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
     * The services among a definition's arguments, also inside lists and iterators: those referenced
     * (as they're defined by now) and those defined inline.
     *
     * @param array<mixed> $arguments
     *
     * @return iterable<Definition>
     */
    private function definitions(ContainerBuilder $container, array $arguments): iterable
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Reference) {
                if ($container->has((string) $argument)) {
                    yield $container->findDefinition((string) $argument);
                }
            } elseif ($argument instanceof Definition) {
                yield $argument;
            } elseif ($argument instanceof ArgumentInterface) {
                yield from $this->definitions($container, $argument->getValues());
            } elseif (\is_array($argument)) {
                yield from $this->definitions($container, $argument);
            }
        }
    }

    private function classOf(ContainerBuilder $container, Definition $definition): string
    {
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        return \is_string($class) ? $class : '';
    }

    private function class(ContainerBuilder $container, string $id): string
    {
        $definition = $container->findDefinition($id);
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return \is_string($class) ? $class : $id;
    }
}
