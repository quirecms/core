<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Assets;
use Quire\Theme\Themes;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Parameter;

/**
 * Makes AssetMapper read the importmap through {@see ImportMapConfigReader}, which adds the scripts of
 * Extend\Assets as entrypoints, and the npm packages of extensions and themes.
 *
 * @internal
 */
final class AssetsPass implements CompilerPassInterface
{
    public const string CONFIG_READER = 'asset_mapper.importmap.config_reader';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONFIG_READER)) {
            return;
        }

        $container->getDefinition(self::CONFIG_READER)
            ->setClass(ImportMapConfigReader::class)
            ->addArgument(new Parameter(Assets::PARAMETER))
            ->addArgument(new Parameter(Themes::CHAIN_PARAMETER))
            ->addArgument(new Parameter(Assets::PACKAGES_PARAMETER));
    }
}
