<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Composer\InstalledVersions;
use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the checks of the slots only quire/privacy fills share (doc 20, conditions C-B and C-C): whether
 * a class is quire/privacy's or core's, and what a service is really built from (the services among its
 * arguments, by reference or inline, and its factory and configurator). They run once the container's
 * services are final, after decoration.
 *
 * @internal
 */
abstract readonly class PrivacySlotPass implements CompilerPassInterface
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

    /**
     * A factory or configurator decides what object a service is, whatever its class says: theirs must
     * be core's or quire/privacy's too. The policy itself is core's Csp, made by nothing else.
     */
    /**
     * @param \Closure(ContainerBuilder, string): void $assert What a factory's or configurator's class must pass
     * @param string                                   $what   The service, for messages when it's $only's
     */
    protected function assertBuiltBy(ContainerBuilder $container, Definition $definition, ?string $only, \Closure $assert, string $what): void
    {
        if (null !== $only && $only !== $this->classOf($container, $definition)) {
            throw new InvalidArgumentException(\sprintf('%s is "%s", not Quire\'s "%s": it decides what loads from other sites, so it\'s Quire\'s own. Change what loads through quire.csp or quire/privacy. See docs/security.md.', $what, $this->classOf($container, $definition), $only));
        }

        foreach (['factory' => $definition->getFactory(), 'configurator' => $definition->getConfigurator()] as $kind => $callable) {
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
                throw new InvalidArgumentException(\sprintf('%s has a %s ("%s"): Quire\'s is made by nothing else. See docs/security.md.', $what, $kind, $class));
            }

            $assert($container, $class);
        }
    }

    /**
     * quire/privacy's: its namespace, and a file of the package where Composer installed it.
     */
    protected function isPrivacy(ContainerBuilder $container, string $class): bool
    {
        return str_starts_with($class, ThirdPartySourcesInterface::NAMESPACE) && null !== $this->privacy && $this->isFrom($container, $class, $this->privacy);
    }

    protected function isFrom(ContainerBuilder $container, string $class, string $directory): bool
    {
        $file = $container->getReflectionClass($class, false)?->getFileName();
        $real = \is_string($file) ? realpath($file) : false;

        return \is_string($real) && str_starts_with($real, $directory);
    }

    protected function core(): string
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
    protected function definitions(ContainerBuilder $container, array $arguments): iterable
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

    protected function classOf(ContainerBuilder $container, Definition $definition): string
    {
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        return \is_string($class) ? $class : '';
    }

    protected function class(ContainerBuilder $container, string $id): string
    {
        $definition = $container->findDefinition($id);
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return \is_string($class) ? $class : $id;
    }
}
