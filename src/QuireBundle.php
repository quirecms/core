<?php

declare(strict_types=1);

namespace Quire;

use Composer\Autoload\ClassLoader;
use Quire\Extension\State\DatabaseExtensionStateStore;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Entry point of the Quire engine inside a Symfony application.
 *
 * @api
 */
final class QuireBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('extensions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('pinned')
                            ->info('Fix the enabled extensions in configuration instead of the database. Use it for immutable production images; enabling or disabling at runtime is then refused.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('enabled')
                            ->info('IDs of the enabled extensions when "pinned" is true.')
                            ->example(['quire-admin', 'acme-reviews'])
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $extensions): bool => !$extensions['pinned'] && [] !== $extensions['enabled'])
                        ->thenInvalid('"quire.extensions.enabled" only applies when "quire.extensions.pinned" is true. Set "pinned: true", or remove the list and use "bin/console quire:extension:enable" instead.')
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'Quire' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__,
                        'prefix' => 'Quire',
                        'alias' => 'Quire',
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => ['Quire\Migrations' => __DIR__.'/../migrations'],
        ]);
    }

    /**
     * @param array<mixed> $config Processed configuration, shaped by {@see configure()}
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{pinned: bool, enabled: list<string>} $extensions */
        $extensions = $config['extensions'];

        $builder->setParameter('quire.composer.installed_json', $this->installedJsonPath());

        $container->import('../config/services.php');

        $services = $container->services();

        if ($extensions['pinned']) {
            $services->set(ExtensionStateStoreInterface::class, PinnedExtensionStateStore::class)
                ->args([$extensions['enabled']]);
        } else {
            $services->set(ExtensionStateStoreInterface::class, DatabaseExtensionStateStore::class)
                ->autowire();
        }
    }

    /**
     * Composer writes installed.json next to its class loader, wherever the vendor directory is.
     */
    private function installedJsonPath(): string
    {
        $classLoaderFile = new \ReflectionClass(ClassLoader::class)->getFileName();

        if (false === $classLoaderFile) {
            throw new \LogicException('Quire must be installed with Composer.');
        }

        return \dirname($classLoaderFile).\DIRECTORY_SEPARATOR.'installed.json';
    }
}
