<?php

declare(strict_types=1);

namespace Quire\Health\Check;

use Quire\Extension\ExtensionManager;
use Quire\Health\HealthCheckInterface;
use Quire\Health\HealthStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks that the running app was built for the extensions that are currently enabled.
 *
 * Extensions are compiled into the app, so after enabling or disabling one the app must be rebuilt.
 * This catches an app that wasn't.
 */
final readonly class ExtensionsCheck implements HealthCheckInterface
{
    /**
     * @param list<string> $builtFor IDs the app was built with, in boot order
     */
    public function __construct(
        private ExtensionManager $extensions,
        #[Autowire(param: 'quire.extensions.boot_order')]
        private array $builtFor,
        #[Autowire(param: 'quire.extensions.state_available')]
        private bool $stateWasAvailable,
    ) {
    }

    public function name(): string
    {
        return 'extensions';
    }

    public function check(): HealthStatus
    {
        if (!$this->stateWasAvailable) {
            return HealthStatus::unhealthy('The app was built without access to the database, so without extensions. Rebuild it with "bin/console cache:clear".');
        }

        try {
            $current = $this->extensions->resolve()->bootOrderIds();
        } catch (\Throwable $e) {
            return HealthStatus::unhealthy($e::class);
        }

        if ($current !== $this->builtFor) {
            return HealthStatus::unhealthy('The enabled extensions changed since the app was built. Rebuild it with "bin/console cache:clear".');
        }

        return HealthStatus::healthy();
    }
}
