<?php

declare(strict_types=1);

namespace Quire;

use Composer\Autoload\ClassLoader;
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
    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('quire.composer.installed_json', $this->installedJsonPath());

        $container->import('../config/services.php');
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
