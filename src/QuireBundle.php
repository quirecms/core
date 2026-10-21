<?php

declare(strict_types=1);

namespace Quire;

use Composer\Autoload\ClassLoader;
use Doctrine\Migrations\Version\Comparator;
use League\CommonMark\Extension\ExtensionInterface as CommonMarkExtensionInterface;
use Quire\Content\ContentRenderer;
use Quire\Database\MigrationOrder;
use Quire\Extension\Boot\CompileTimeState;
use Quire\Extension\Boot\ExtensionBooter;
use Quire\Extension\Boot\ExtensionStateResource;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\DatabaseExtensionStateStore;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Quire\Formatter\FormatInterface;
use Quire\Formatter\Formatter;
use Quire\Formatter\HtmlProcessorInterface;
use Quire\Formatter\ShortcodeInterface;
use Quire\Formatter\SourceProcessorInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
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
                        ->scalarNode('installed_json')
                            ->info('Path to Composer\'s installed.json, which lists the installed extensions. Defaults to the one in the vendor directory; tests point it at fixtures.')
                            ->defaultNull()
                        ->end()
                        ->integerNode('check_interval')
                            ->info('How often, in seconds, each PHP worker checks whether the enabled extensions changed elsewhere (e.g. on another server), and refreshes this machine if so. 0 disables the check. Not used when pinned.')
                            ->defaultValue(5)
                            ->min(0)
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

        // Core first, then extensions in boot order: see MigrationOrder. Core's own migrations are
        // registered by its extend.php, like an extension's.
        $builder->prependExtensionConfig('doctrine_migrations', [
            'services' => [Comparator::class => MigrationOrder::class],
        ]);

        // Rendered content is cached in its own pool, so it can be cleared on its own.
        $builder->prependExtensionConfig('framework', [
            'cache' => ['pools' => [ContentRenderer::CACHE_POOL => ['adapter' => 'cache.app']]],
        ]);

        // Extensions boot here, before other bundles process their configuration, so extenders can
        // still add to it (e.g. Twig paths).
        $this->bootExtensions($builder);
    }

    /**
     * @param array<mixed> $config Processed configuration, shaped by {@see configure()}
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{pinned: bool, enabled: list<string>} $extensions */
        $extensions = $config['extensions'];

        $container->import('../config/services.php');

        // The formatter finds its services by this tag, however they were registered.
        foreach ([FormatInterface::class, ShortcodeInterface::class, SourceProcessorInterface::class, HtmlProcessorInterface::class, CommonMarkExtensionInterface::class] as $interface) {
            $builder->registerForAutoconfiguration($interface)->addTag(Formatter::SERVICE_TAG);
        }

        $services = $container->services();

        if ($extensions['pinned']) {
            $services->set(ExtensionStateStoreInterface::class, PinnedExtensionStateStore::class)
                ->args([$extensions['enabled']]);
        } else {
            $services->set(ExtensionStateStoreInterface::class, DatabaseExtensionStateStore::class)
                ->autowire();
        }
    }

    private function bootExtensions(ContainerBuilder $builder): void
    {
        $config = $this->extensionsConfig($builder);
        $installedJson = $config['installed_json'] ?? $this->defaultInstalledJsonPath();

        if (is_file($installedJson)) {
            $builder->addResource(new FileResource($installedJson));
        }

        if ($config['pinned']) {
            $enabledIds = $config['enabled'];
            $stateAvailable = true;
            $stateHash = null;
        } else {
            $state = CompileTimeState::read();
            $enabledIds = $state->enabledIds;
            $stateAvailable = $state->available;
            $stateHash = $state->hash();
            $builder->addResource(new ExtensionStateResource($stateHash));
        }

        $projectDir = $builder->getParameter('kernel.project_dir');

        $builder->setParameter('quire.composer.installed_json', $installedJson);
        $builder->setParameter('quire.extensions.state_available', $stateAvailable);
        // What the build was made for, so workers notice changes made elsewhere (null when pinned).
        $builder->setParameter('quire.extensions.state_hash', $stateHash);
        $builder->setParameter('quire.extensions.check_interval', $config['check_interval']);
        $builder->setParameter('quire.runtime.default_admin_url', 'http://localhost:2019');

        new ExtensionBooter(new InstalledExtensions($installedJson))
            ->boot($builder, $enabledIds, $projectDir.\DIRECTORY_SEPARATOR.'extend.php', \dirname(__DIR__).\DIRECTORY_SEPARATOR.'extend.php');

        // Changes when the formatter setup or any installed package changes, so cached HTML is never stale.
        $builder->setParameter('quire.formatter.fingerprint', substr(hash('xxh128', json_encode($builder->getParameterBag()->all()[Formatter::PARAMETER] ?? [], \JSON_THROW_ON_ERROR).(is_file($installedJson) ? hash_file('xxh128', $installedJson) : '')), 0, 16));
    }

    /**
     * The "quire.extensions" configuration, processed early: it's needed before the regular loading phase.
     *
     * @return array{pinned: bool, enabled: list<string>, installed_json: ?string, check_interval: int}
     */
    private function extensionsConfig(ContainerBuilder $builder): array
    {
        $extension = $this->getContainerExtension();

        if (!$extension instanceof ConfigurationExtensionInterface) {
            throw new \LogicException('The Quire bundle extension must provide its configuration.');
        }

        $configuration = $extension->getConfiguration([], $builder);

        if (!$configuration instanceof \Symfony\Component\Config\Definition\ConfigurationInterface) {
            throw new \LogicException('The Quire bundle extension must provide its configuration.');
        }

        /** @var list<array<mixed>> $configs */
        $configs = $builder->getParameterBag()->resolveValue($builder->getExtensionConfig($extension->getAlias()));

        /** @var array{extensions: array{pinned: bool, enabled: list<string>, installed_json: ?string, check_interval: int}} $processed */
        $processed = new Processor()->processConfiguration($configuration, $configs);

        return $processed['extensions'];
    }

    /**
     * Composer writes installed.json next to its class loader, wherever the vendor directory is.
     */
    private function defaultInstalledJsonPath(): string
    {
        $classLoaderFile = new \ReflectionClass(ClassLoader::class)->getFileName();

        if (false === $classLoaderFile) {
            throw new \LogicException('Quire must be installed with Composer.');
        }

        return \dirname($classLoaderFile).\DIRECTORY_SEPARATOR.'installed.json';
    }
}
