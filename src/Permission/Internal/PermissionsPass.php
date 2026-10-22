<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Quire\Extend\Permissions;
use Quire\Permission\Security\AbilityVoter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Makes the rules of Extend\Permissions services that {@see AbilityVoter} can reach.
 *
 * @internal
 */
final class PermissionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AbilityVoter::class)) {
            return;
        }

        /** @var array{rules?: list<array{class: string}>} $config */
        $config = $container->getParameterBag()->all()[Permissions::PARAMETER] ?? [];
        $references = [];

        foreach ($config['rules'] ?? [] as $rule) {
            if (!$container->hasDefinition($rule['class'])) {
                $container->register($rule['class'], $rule['class'])->setAutowired(true)->setAutoconfigured(true);
            }

            $references[$rule['class']] = new Reference($rule['class']);
        }

        $container->getDefinition(AbilityVoter::class)->setArgument('$rules', ServiceLocatorTagPass::register($container, $references));
    }
}
