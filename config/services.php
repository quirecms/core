<?php

declare(strict_types=1);

use Quire\Extension\InstalledExtensions;
use Quire\Health\HealthCheckInterface;
use Quire\Routing\ExtensionRouteLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

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
            '../src/Settings/{SettingDefinition,SettingRecord,SettingType}.php',
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
        ]);

    $services->set(InstalledExtensions::class)
        ->arg('$installedJsonPath', param('quire.composer.installed_json'));

    $services->set(ExtensionRouteLoader::class)
        ->tag('routing.loader');
};
