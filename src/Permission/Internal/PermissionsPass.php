<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Quire\Extend\Auth;
use Quire\Extend\Permissions;
use Quire\Permission\AccessExplainer;
use Quire\Permission\Security\AbilityVoter;
use Quire\User\Security\ExtensionAuthenticators;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Makes the rules of Extend\Permissions and the authenticators of Extend\Auth services that
 * {@see AbilityVoter}, {@see AccessExplainer} and {@see ExtensionAuthenticators} can reach.
 *
 * @internal
 */
final class PermissionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $parameters = $container->getParameterBag()->all();
        /** @var array{rules?: list<array{class: string}>} $permissions */
        $permissions = $parameters[Permissions::PARAMETER] ?? [];
        /** @var list<array{class: string}> $authenticators */
        $authenticators = $parameters[Auth::PARAMETER] ?? [];

        $this->locate($container, AbilityVoter::class, '$rules', $permissions['rules'] ?? []);
        $this->locate($container, AccessExplainer::class, '$rules', $permissions['rules'] ?? []);
        $this->locate($container, ExtensionAuthenticators::class, '$services', $authenticators);
    }

    /**
     * @param list<array{class: string}> $registrations
     */
    private function locate(ContainerBuilder $container, string $consumer, string $argument, array $registrations): void
    {
        if (!$container->hasDefinition($consumer)) {
            return;
        }

        $references = [];

        foreach ($registrations as $registration) {
            if (!$container->hasDefinition($registration['class'])) {
                $container->register($registration['class'], $registration['class'])->setAutowired(true)->setAutoconfigured(true);
            }

            $references[$registration['class']] = new Reference($registration['class']);
        }

        $container->getDefinition($consumer)->setArgument($argument, ServiceLocatorTagPass::register($container, $references));
    }
}
