<?php

declare(strict_types=1);

use Quire\Health\HealthCheckInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
        ]);
};
