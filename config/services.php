<?php

declare(strict_types=1);

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command as MigrationCommand;
use Quire\Database\MigrationsFactory;
use Quire\Database\SchemaAssetFilter;
use Quire\Extension\InstalledExtensions;
use Quire\Health\HealthCheckInterface;
use Quire\Routing\ContentRouteLoader;
use Quire\Routing\ExtensionRouteLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->instanceof(HealthCheckInterface::class)
        ->tag('quire.health_check');

    $services->load('Quire\\', '../src/')
        ->exclude([
            '../src/QuireBundle.php',
            '../src/Database/{DatabaseSchema,MigrationStorage}.php',
            // Extenders and their context are used while the container is built, not as services.
            '../src/Extend/',
            '../src/Extension/Boot/',
            '../src/Health/HealthStatus.php',
            '../src/Extension/ExtensionManifest.php',
            '../src/Extension/ExtensionType.php',
            '../src/Extension/Exception/',
            '../src/Extension/Event/',
            '../src/Extension/ResolvedExtensions.php',
            '../src/Extension/Rebuild/RebuildFailedException.php',
            // The state store is chosen in QuireBundle::loadExtension(), depending on "quire.extensions.pinned".
            '../src/Extension/State/',
            '../src/Settings/{Event,Exception}/',
            '../src/Settings/{SettingDefinition,SettingType}.php',
            '../src/Maker/ExtensionSkeleton.php',
            '../src/Content/Type/{Capability,ContentTypeDefinition}.php',
            '../src/Content/Field/{FieldDefinition,FieldExpression,FieldType}.php',
            '../src/Content/Internal/Fields.php',
            '../src/Formatter/{CompiledText,FormatContext,FormatterProfile,Shortcode}.php',
            '../src/Formatter/{Exception,Internal}/',
            '../src/Content/Type/Exception/',
            '../src/Content/{Content,Status}.php',
            '../src/Content/{Command,Event,Exception}/',
            '../src/Content/Internal/{Locales,Row}.php',
            '../src/Content/Revision/Revision.php',
            '../src/Content/Workflow/ContentSubject.php',
            '../src/Content/Console/ConsoleErrors.php',
            '../src/Content/Query/{ContentPage,ContentQuery}.php',
            '../src/Content/Query/Exception/',
            '../src/Content/Query/Internal/{NoMatch,QueryFields,QueryServicesPass}.php',
            '../src/Content/Permalink/{PermalinkPattern,Redirect,Resolution}.php',
            '../src/Content/Permalink/Internal/PermalinkSettings.php',
            '../src/Backup/BackupContents.php',
            '../src/Backup/Exception/',
            '../src/Theme/Exception/',
            '../src/Theme/Internal/{ThemeChain,TemplatePathsPass,SlotsPass,AssetsPass,ImportMapConfigReader}.php',
        ]);

    $services->set(InstalledExtensions::class)
        ->arg('$installedJsonPath', param('quire.composer.installed_json'));

    $services->set(ExtensionRouteLoader::class)
        ->tag('routing.loader');

    $services->set(ContentRouteLoader::class)
        ->tag('routing.loader');

    $services->set(SchemaAssetFilter::class)
        ->tag(SchemaAssetFilter::TAG);

    // Quire's migrations have their own Doctrine Migrations setup and commands, next to the application's.
    $services->set(MigrationsFactory::SERVICE, DependencyFactory::class)
        ->factory([service(MigrationsFactory::class), 'create']);

    foreach ([
        'migrate' => MigrationCommand\MigrateCommand::class,
        'status' => MigrationCommand\StatusCommand::class,
        'list' => MigrationCommand\ListCommand::class,
        'execute' => MigrationCommand\ExecuteCommand::class,
        'version' => MigrationCommand\VersionCommand::class,
        'up-to-date' => MigrationCommand\UpToDateCommand::class,
        'current' => MigrationCommand\CurrentCommand::class,
        'latest' => MigrationCommand\LatestCommand::class,
    ] as $name => $class) {
        // Not autoconfigured: that would also register them under Doctrine's own names.
        $services->set('quire.migrations.command.'.$name, $class)
            ->autowire(false)
            ->autoconfigure(false)
            ->args([service(MigrationsFactory::SERVICE), 'quire:migrations:'.$name])
            ->tag('console.command', ['command' => 'quire:migrations:'.$name]);
    }
};
