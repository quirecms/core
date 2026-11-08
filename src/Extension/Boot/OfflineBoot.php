<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Quire\Extension\InstalledExtensions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs the extenders of the given extensions in a container of their own, as they would when the
 * application is built with them enabled, without building it: what migrations and disks the code has
 * then. For a restore that enables other extensions, and for reaching the disks of disabled ones.
 *
 * @internal
 */
final readonly class OfflineBoot
{
    public function __construct(
        private InstalledExtensions $installed,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @param list<string> $enabledIds
     *
     * @throws \Quire\Extend\ExtenderException when an extender refuses to run
     */
    public function boot(array $enabledIds): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.environment', $this->environment);
        $container->setParameter('kernel.debug', false);

        new ExtensionBooter($this->installed)->boot(
            $container,
            $enabledIds,
            $this->projectDir.\DIRECTORY_SEPARATOR.'extend.php',
            ExtensionBooter::coreExtendFile(),
        );

        return $container;
    }
}
