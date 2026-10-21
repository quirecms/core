<?php

declare(strict_types=1);

use Quire\Extension\InstalledExtensions;
use Quire\Health\HealthCheckInterface;
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
            '../src/Health/HealthStatus.php',
            '../src/Extension/ExtensionManifest.php',
            '../src/Extension/ExtensionType.php',
            '../src/Extension/Exception/',
        ]);

    $services->set(InstalledExtensions::class)
        ->arg('$installedJsonPath', param('quire.composer.installed_json'));
};
