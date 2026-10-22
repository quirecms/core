<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Theme\Slots;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Orders the fragments of each slot by priority (in the order extensions boot when equal), and makes
 * the renderer classes services that {@see Slots} can reach.
 *
 * @internal
 */
final class SlotsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Slots::class)) {
            return;
        }

        /** @var list<array{slot: string, template: ?string, class: ?string, priority: int, owner: string}> $fragments */
        $fragments = $container->getParameterBag()->all()[Slots::PARAMETER];
        $order = array_flip(array_keys($fragments));
        uksort($fragments, static fn (int $a, int $b): int => [$fragments[$a]['slot'], -$fragments[$a]['priority'], $order[$a]] <=> [$fragments[$b]['slot'], -$fragments[$b]['priority'], $order[$b]]);
        $container->setParameter(Slots::PARAMETER, array_values($fragments));

        $references = [];

        foreach ($fragments as $fragment) {
            $class = $fragment['class'];

            if (null === $class) {
                continue;
            }

            if (!$container->hasDefinition($class)) {
                $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true);
            }

            $references[$class] = new Reference($class);
        }

        $container->getDefinition(Slots::class)->setArgument('$renderers', ServiceLocatorTagPass::register($container, $references));
    }
}
