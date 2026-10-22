<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the {@see QueryRunner} a locator of the scope, visibility and relation classes that extenders
 * registered, and only those.
 *
 * It runs after all configuration is loaded, so it sees the final service definitions, however they were
 * registered (an extender, an extension's service autoloading or core's services.php).
 *
 * @internal
 *
 * @phpstan-import-type Config from QueryRunner
 */
final class QueryServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(QueryRunner::class)) {
            return;
        }

        /** @var Config|null $config */
        $config = $container->getParameterBag()->all()[QueryRunner::PARAMETER] ?? null;
        $references = [];

        foreach ([...array_values($config['scopes'] ?? []), ...$config['visibility'] ?? [], ...array_values($config['relations'] ?? [])] as $registration) {
            $class = $registration['class'];

            if (!$container->hasDefinition($class)) {
                $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true);
            }

            $references[$class] = new Reference($class);
        }

        $container->getDefinition(QueryRunner::class)->setArgument('$services', ServiceLocatorTagPass::register($container, $references));
    }
}
