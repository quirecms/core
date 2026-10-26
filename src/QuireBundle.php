<?php

declare(strict_types=1);

namespace Quire;

use Composer\Autoload\ClassLoader;
use League\CommonMark\Extension\ExtensionInterface as CommonMarkExtensionInterface;
use Quire\Action\ActionInterface;
use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\ContentRenderer;
use Quire\Content\Internal\RelationFieldsPass;
use Quire\Content\Permalink\Internal\PermalinkSettings;
use Quire\Content\Query\Internal\QueryServicesPass;
use Quire\Extend\Actions;
use Quire\Extend\ContentChecks;
use Quire\Extend\ExtenderContext;
use Quire\Extend\Permissions;
use Quire\Extend\Storage;
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
use Quire\Jobs\Job;
use Quire\Permission\Internal\ContentAbilities;
use Quire\Permission\Internal\PermissionsPass;
use Quire\Routing\ExtensionRouteLoader;
use Quire\Settings\SettingValidatorInterface;
use Quire\Storage\Internal\DiskConfig;
use Quire\Storage\StorageDisks;
use Quire\Theme\Internal\AssetsPass;
use Quire\Theme\Internal\SlotsPass;
use Quire\Theme\Internal\TemplatePathsPass;
use Quire\Theme\Themes;
use Quire\User\CurrentUser;
use Quire\User\Security\FormThrottle;
use Quire\User\Security\UserProvider;
use Quire\User\User;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Entry point of the Quire engine inside a Symfony application.
 *
 * @api
 */
final class QuireBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new QueryServicesPass());
        $container->addCompilerPass(new TemplatePathsPass());
        $container->addCompilerPass(new SlotsPass());
        $container->addCompilerPass(new AssetsPass());
        $container->addCompilerPass(new PermissionsPass());
        $container->addCompilerPass(new RelationFieldsPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('routing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('prefix')
                            ->info('Path that the homepage and all content URLs are under, e.g. "/cms" when Quire is added to an existing application. Empty for the root of the site.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $prefix): bool => !\is_string($prefix) || 1 !== preg_match('#^(?:/[a-z0-9._~-]+)*$#', $prefix))
                                ->thenInvalid('"quire.routing.prefix" must be empty or a path such as "/cms": starting with "/", in lowercase, without a trailing "/".')
                            ->end()
                        ->end()
                        ->scalarNode('admin_prefix')
                            ->info('Path of the admin interface: the routes extensions register in the "admin" group, e.g. "/cms-admin" when the application uses "/admin" itself.')
                            ->defaultValue('/admin')
                            ->validate()
                                ->ifTrue(static fn (mixed $prefix): bool => !\is_string($prefix) || 1 !== preg_match('#^(?:/[a-z0-9._~-]+)+$#', $prefix))
                                ->thenInvalid('"quire.routing.admin_prefix" must be a path such as "/admin": starting with "/", in lowercase, without a trailing "/".')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('users')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('login')
                            ->info('Whether Quire\'s sign-in page and sign-out URL exist. Defaults to true, or to false with "host" users, who sign in through the application.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('host')
                            ->info('Use the users of the application Quire is installed in: whoever its firewall signs in gets a linked Quire user, with roles mapped from theirs on every request. See docs/users.md.')
                            ->canBeEnabled()
                            ->children()
                                ->arrayNode('roles')
                                    ->info('The application\'s Symfony roles and the Quire roles they give, e.g. {ROLE_ORGANIZER: editor, ROLE_SUPER_ADMIN: [admin]}. Role hierarchy applies.')
                                    ->useAttributeAsKey('role')
                                    ->arrayPrototype()
                                        ->beforeNormalization()->castToArray()->end()
                                        ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                                ->scalarNode('display_name')
                                    ->info('Property path of the name to show for the application\'s users, e.g. "fullName"; their user identifier by default.')
                                    ->defaultNull()
                                ->end()
                                ->scalarNode('email')
                                    ->info('Property path of the email of the application\'s users, e.g. "email"; none by default.')
                                    ->defaultNull()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->info('Where uploaded files are kept. See docs/storage.md.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('root')
                            ->info('Directory of local disks without a "path": public disks in "public/<disk>", private ones in "private/<disk>". Keep it on a volume that survives deployments.')
                            ->defaultValue('%kernel.project_dir%/storage')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('disks')
                            ->info('Disks by name: those that extensions declare (to put them elsewhere, e.g. on S3), and the site\'s own.')
                            // Disk names keep their "-": by default, configuration keys turn it into "_".
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->enumNode('adapter')
                                        ->info('"local" (a directory), "s3" (any S3-compatible service; needs league/flysystem-async-aws-s3), or "service" (a Flysystem FilesystemOperator service of the application, e.g. from flysystem-bundle)')
                                        ->values(['local', 's3', 'service'])
                                        ->defaultValue('local')
                                    ->end()
                                    ->booleanNode('public')
                                        ->info('Whether files are served to everyone. Set by the extension that declares the disk; required for the site\'s own disks.')
                                        ->defaultNull()
                                    ->end()
                                    ->scalarNode('url')
                                        ->info('Base URL of the files of a public disk, e.g. a CDN in front of the bucket. By default Quire serves local disks at /storage/<disk>, and S3 disks link to the bucket.')
                                        ->defaultNull()
                                    ->end()
                                    ->scalarNode('path')->info('local: the directory; "<root>/public/<disk>" or "<root>/private/<disk>" by default')->defaultNull()->end()
                                    ->scalarNode('bucket')->info('s3: the bucket')->defaultNull()->end()
                                    ->scalarNode('region')->info('s3: the region')->defaultValue('us-east-1')->end()
                                    ->scalarNode('endpoint')->info('s3: the service\'s address, for services other than AWS, e.g. "https://<account>.r2.cloudflarestorage.com"')->defaultNull()->end()
                                    ->scalarNode('access_key')->info('s3: the access key ID, e.g. "%env(S3_ACCESS_KEY)%"')->defaultNull()->end()
                                    ->scalarNode('secret_key')->info('s3: the secret access key, e.g. "%env(S3_SECRET_KEY)%"')->defaultNull()->end()
                                    ->scalarNode('prefix')->info('s3: a directory in the bucket, so disks can share one')->defaultValue('')->end()
                                    ->booleanNode('path_style')->info('s3: address the bucket in the path ("https://host/bucket/…"), as most services other than AWS need')->defaultFalse()->end()
                                    ->scalarNode('service')->info('service: the ID of the FilesystemOperator service')->defaultNull()->end()
                                ->end()
                                ->validate()
                                    ->ifTrue(static fn (array $disk): bool => 's3' === $disk['adapter'] && (null === $disk['bucket'] || '' === $disk['bucket']))
                                    ->thenInvalid('S3 disks need a "bucket".')
                                ->end()
                                ->validate()
                                    ->ifTrue(static fn (array $disk): bool => 'service' === $disk['adapter'] && (null === $disk['service'] || '' === $disk['service']))
                                    ->thenInvalid('Disks with the "service" adapter need the "service" ID.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
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
                        ->scalarNode('theme')
                            ->info('ID of the site\'s theme when "pinned" is true; null for Quire\'s built-in templates. Otherwise the theme is a site setting: use "bin/console quire:theme:use".')
                            ->defaultNull()
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
                    ->validate()
                        ->ifTrue(static fn (array $extensions): bool => !$extensions['pinned'] && null !== $extensions['theme'])
                        ->thenInvalid('"quire.extensions.theme" only applies when "quire.extensions.pinned" is true. Set "pinned: true", or remove it and use "bin/console quire:theme:use" instead.')
                    ->end()
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Rendered content is cached in its own pool, so it can be cleared on its own.
        $builder->prependExtensionConfig('framework', [
            'cache' => ['pools' => [
                ContentRenderer::CACHE_POOL => ['adapter' => 'cache.app'],
                // How often forms such as the registration were sent. With several servers, make
                // cache.app shared (e.g. Redis), or each server counts on its own.
                FormThrottle::CACHE_POOL => ['adapter' => 'cache.app'],
            ]],
        ]);

        // Background jobs: the transport "quire" (by default the table quire.job, see Jobs\Job), with
        // retries, and failed jobs kept in the database for a look and a retry. Additions only: the
        // application's own transports and routing stay as they are.
        $builder->setParameter('quire.jobs.default_dsn', 'quire://');
        $builder->prependExtensionConfig('framework', [
            'messenger' => [
                'transports' => [
                    Job::TRANSPORT => [
                        'dsn' => '%env(default:quire.jobs.default_dsn:QUIRE_JOBS_DSN)%',
                        // About 20 minutes of retries (10 s, 30 s, 1.5 min, 4.5 min, 13.5 min): longer than most outages of a mail server or an API.
                        'retry_strategy' => ['max_retries' => 5, 'delay' => 10_000, 'multiplier' => 3, 'max_delay' => 3_600_000],
                        'failure_transport' => Job::FAILED_TRANSPORT,
                    ],
                    Job::FAILED_TRANSPORT => 'quire://default?queue=failed',
                ],
                'routing' => [Job::class => Job::TRANSPORT],
            ],
        ]);

        // Quire's users for Symfony's security: a password hasher, and the user provider "quire_users".
        // Only additions: which firewalls use them is up to the application, so a host application's
        // own security setup stays as it is.
        if ($builder->hasExtension('security')) {
            $builder->prependExtensionConfig('security', [
                'password_hashers' => [User::class => 'auto'],
                'providers' => ['quire_users' => ['id' => UserProvider::class]],
            ]);
        }

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
        /** @var array{prefix: string, admin_prefix: string} $routing */
        $routing = $config['routing'];
        $builder->setParameter('quire.routing.prefix', $routing['prefix']);
        $builder->setParameter(ExtensionRouteLoader::ADMIN_PREFIX_PARAMETER, $routing['admin_prefix']);

        /** @var array{login: ?bool, host: array{enabled: bool, roles: array<string, list<string>>, display_name: ?string, email: ?string}} $users */
        $users = $config['users'];
        $builder->setParameter(CurrentUser::LOGIN_PARAMETER, $users['login'] ?? !$users['host']['enabled']);
        $builder->setParameter(CurrentUser::HOST_PARAMETER, $users['host']['enabled'] ? $this->hostUsers($users['host'], $builder) : null);

        /** @var array{root: string, disks: array<string, array<string, mixed>>} $storage */
        $storage = $config['storage'];
        $this->storageDisks($storage, $builder);

        $container->import('../config/services.php');

        // The formatter finds its services by this tag, however they were registered.
        $builder->registerForAutoconfiguration(SettingValidatorInterface::class)->addTag(SettingValidatorInterface::TAG);
        // Content checks too: core's own classes are registered again by services.php.
        $builder->registerForAutoconfiguration(ContentCheckInterface::class)->addTag(ContentChecks::TAG);
        $builder->registerForAutoconfiguration(ActionInterface::class)->addTag(Actions::TAG);

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

    /**
     * @param array{roles: array<string, list<string>>, display_name: ?string, email: ?string} $host
     *
     * @return array{roles: array<string, list<string>>, display_name: ?string, email: ?string}
     */
    private function hostUsers(array $host, ContainerBuilder $builder): array
    {
        $permissions = $builder->getParameterBag()->all()[Permissions::PARAMETER] ?? null;
        $known = \is_array($permissions) && \is_array($permissions['roles'] ?? null) ? $permissions['roles'] : [];

        foreach ($host['roles'] as $hostRole => $roles) {
            foreach ($roles as $role) {
                if (!isset($known[$role])) {
                    throw new InvalidConfigurationException(\sprintf('"quire.users.host.roles.%s" gives the unknown role "%s". Roles: %s.', $hostRole, $role, implode(', ', array_keys($known))));
                }
            }
        }

        return ['roles' => $host['roles'], 'display_name' => $host['display_name'], 'email' => $host['email']];
    }

    /**
     * The disks the extensions declare, placed where the configuration says (a directory under the storage
     * root by default), plus the site's own.
     *
     * @param array{root: string, disks: array<string, array<string, mixed>>} $storage
     */
    private function storageDisks(array $storage, ContainerBuilder $builder): void
    {
        $declared = [];

        /** @var list<array{name: string, public: bool, label: ?string, owner: string}> $declarations */
        $declarations = (array) ($builder->getParameterBag()->all()[Storage::PARAMETER] ?? []);

        foreach ($declarations as $disk) {
            $declared[$disk['name']] = $disk;
        }

        $disks = [];
        $services = [];

        foreach (array_keys($declared + $storage['disks']) as $name) {
            $configured = $storage['disks'][$name] ?? ['adapter' => 'local', 'public' => null, 'url' => null, 'path' => null, 'service' => null];
            $declaration = $declared[$name] ?? null;

            if (1 !== preg_match(Storage::NAME_PATTERN, $name)) {
                throw new InvalidConfigurationException(\sprintf('"quire.storage.disks.%s": disk names have lowercase letters, digits, "-" and "_", and start with a letter.', $name));
            }

            if (null === $declaration && null === $configured['public']) {
                throw new InvalidConfigurationException(\sprintf('"quire.storage.disks.%s.public" is needed: no extension declares the disk, so say whether its files are served to everyone.', $name));
            }

            if (null !== $declaration && null !== $configured['public'] && $configured['public'] !== $declaration['public']) {
                throw new InvalidConfigurationException(\sprintf('"quire.storage.disks.%s.public" contradicts "%s", which declares the disk %s.', $name, $declaration['owner'], $declaration['public'] ? 'public' : 'private'));
            }

            $public = (bool) ($declaration['public'] ?? $configured['public']);
            $disks[$name] = [
                ...$configured,
                'public' => $public,
                'path' => $configured['path'] ?? DiskConfig::defaultPath($storage['root'], $public, $name),
                'label' => $declaration['label'] ?? null,
                'owner' => $declaration['owner'] ?? ExtenderContext::SITE,
            ];

            if ('service' === $configured['adapter'] && \is_string($configured['service'])) {
                $services[$name] = new Reference($configured['service']);
            }
        }

        ksort($disks);
        $this->assertSeparateDisks($disks, $builder);
        $builder->setParameter(StorageDisks::PARAMETER, $disks);
        $builder->setParameter(StorageDisks::ROOT_PARAMETER, $storage['root']);
        $builder->setDefinition(StorageDisks::SERVICES_LOCATOR, new Definition(ServiceLocator::class, [$services]))->addTag('container.service_locator');
    }

    /**
     * Two disks in one place would see each other's files, and the garbage collector would delete them as
     * unrecorded: each disk needs a directory, or bucket and prefix, of its own.
     *
     * @param array<string, array<string, mixed>> $disks
     */
    private function assertSeparateDisks(array $disks, ContainerBuilder $builder): void
    {
        $locations = [];

        foreach ($disks as $name => $disk) {
            $disk = DiskConfig::fromArray($name, $disk);
            $path = $builder->getParameterBag()->resolveValue($disk->path);
            $location = match ($disk->adapter) {
                'local' => 'local:'.rtrim(\is_string($path) ? $path : $disk->path, '/').'/',
                's3' => 's3:'.($disk->endpoint ?? 'aws').'/'.$disk->bucket.'/'.trim($disk->prefix, '/').'/',
                'service' => null,
            };

            if (null === $location) {
                continue;
            }

            foreach ($locations as $other => $otherLocation) {
                if (str_starts_with($location, $otherLocation) || str_starts_with($otherLocation, $location)) {
                    throw new InvalidConfigurationException(\sprintf('The storage disks "%s" and "%s" are in the same place, or one inside the other. Give each its own directory, or bucket and prefix.', $other, $name));
                }
            }

            $locations[$name] = $location;
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
            $activeTheme = $config['theme'];
            $stateAvailable = true;
            $stateHash = null;
        } else {
            $state = CompileTimeState::read();
            $enabledIds = $state->enabledIds;
            $activeTheme = $state->activeTheme;
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
        $builder->setParameter(Themes::PINNED_PARAMETER, $config['pinned']);
        $builder->setParameter('quire.runtime.default_admin_url', 'http://localhost:2019');

        $installed = new InstalledExtensions($installedJson);
        $this->mapAssets($builder, $installed);

        new ExtensionBooter($installed)
            ->boot($builder, $enabledIds, $projectDir.\DIRECTORY_SEPARATOR.'extend.php', ExtensionBooter::coreExtendFile(), $activeTheme);

        PermalinkSettings::declare($builder);
        ContentAbilities::declare($builder);

        // Changes when the formatter setup or any installed package changes, so cached HTML is never stale.
        $builder->setParameter('quire.formatter.fingerprint', substr(hash('xxh128', json_encode($builder->getParameterBag()->all()[Formatter::PARAMETER] ?? [], \JSON_THROW_ON_ERROR).(is_file($installedJson) ? hash_file('xxh128', $installedJson) : '')), 0, 16));
    }

    /**
     * Makes the assets/ directory of every installed extension and theme available to AssetMapper as
     * "<id>/…", e.g. "acme-reviews/reviews.css". Installed, not only enabled: a production image compiles
     * its assets while it's built, without the database, so it must include those of every package.
     */
    private function mapAssets(ContainerBuilder $builder, InstalledExtensions $installed): void
    {
        $paths = [];

        foreach ($installed->extensionsAndThemes() as $manifest) {
            $directory = $manifest->path.\DIRECTORY_SEPARATOR.'assets';
            $builder->addResource(new FileExistenceResource($directory));

            if (is_dir($directory)) {
                $paths[$directory] = $manifest->id;
            }
        }

        if ([] !== $paths) {
            $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => $paths]]);
        }
    }

    /**
     * The "quire.extensions" configuration, processed early: it's needed before the regular loading phase.
     *
     * @return array{pinned: bool, enabled: list<string>, theme: ?string, installed_json: ?string, check_interval: int}
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

        /** @var array{extensions: array{pinned: bool, enabled: list<string>, theme: ?string, installed_json: ?string, check_interval: int}} $processed */
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
