<?php

declare(strict_types=1);

namespace Quire\Setup\Internal;

use Quire\Extend\SetupSteps;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes every registered setup step a tagged service once all extenders have run, so the order of
 * an extension's extenders never matters: an Extend\Services() after Extend\SetupSteps() would
 * otherwise replace the step's definition, tag and all, and the step would silently never show.
 * A step that isn't a service yet gets one, autowired.
 *
 * @internal
 */
final readonly class SetupStepsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var list<array{id: string, class: string, priority: int, owner: string}> $registered */
        $registered = $container->getParameterBag()->all()[SetupSteps::PARAMETER] ?? [];

        foreach ($registered as $step) {
            if (!$container->hasDefinition($step['class'])) {
                $container->register($step['class'], $step['class'])->setAutowired(true)->setAutoconfigured(true);
            }

            $definition = $container->getDefinition($step['class']);

            if (!$definition->hasTag(SetupSteps::TAG)) {
                $definition->addTag(SetupSteps::TAG);
            }
        }
    }
}
