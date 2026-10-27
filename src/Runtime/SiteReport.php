<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Composer\InstalledVersions;
use Doctrine\Migrations\DependencyFactory;
use Quire\Database\MigrationsFactory;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Health\HealthCheckInterface;
use Quire\Settings\Settings;
use Quire\Theme\Themes;
use Quire\User\Role;
use Quire\User\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Everything about a site that the tools managing it need at once: what runs (Quire, PHP, Symfony,
 * extensions, theme), whether it's set up, its database's migrations, and its health. Parts that
 * need the database say so instead of failing when it can't be reached.
 *
 * @internal
 *
 * @phpstan-type Report array{
 *     contract: int,
 *     quire: array{version: string, reference: ?string},
 *     php: string,
 *     symfony: string,
 *     environment: string,
 *     site: array{title: ?string, locale: ?string, timezone: ?string, url: ?string, installed: ?bool},
 *     extensions: list<array{id: string, package: string, version: string, type: string, status: string}>,
 *     theme: ?string,
 *     migrations: array{executed: ?int, pending: ?int},
 *     health: array{status: string, checks: array<string, array{status: string, message?: string}>},
 * }
 */
final readonly class SiteReport
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
        private Themes $themes,
        private Settings $settings,
        private UserRepository $users,
        #[Autowire(service: MigrationsFactory::SERVICE)]
        private DependencyFactory $migrations,
        #[AutowireIterator('quire.health_check')]
        private iterable $checks,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        #[Autowire(param: 'router.request_context.base_url')]
        private string $siteUri,
    ) {
    }

    /**
     * @return Report
     */
    public function report(): array
    {
        $resolved = $this->extensions->resolve();

        return [
            'contract' => ServerContract::VERSION,
            'quire' => [
                'version' => InstalledVersions::isInstalled('quire/core') ? (InstalledVersions::getPrettyVersion('quire/core') ?? 'unknown') : 'unknown',
                'reference' => InstalledVersions::isInstalled('quire/core') ? InstalledVersions::getReference('quire/core') : null,
            ],
            'php' => \PHP_VERSION,
            'symfony' => Kernel::VERSION,
            'environment' => $this->environment,
            'site' => [
                'title' => $this->attempt(fn (): string => $this->settings->getString('site.title')),
                'locale' => $this->attempt(fn (): string => $this->settings->getString('site.locale')),
                'timezone' => $this->attempt(fn (): string => $this->settings->getString('site.timezone')),
                'url' => '' === $this->siteUri ? null : $this->siteUri,
                'installed' => $this->attempt(fn (): bool => [] !== $this->users->all(Role::ADMIN, 1)),
            ],
            'extensions' => array_map(static fn (ExtensionManifest $manifest): array => [
                'id' => $manifest->id,
                'package' => $manifest->packageName,
                'version' => $manifest->version,
                'type' => strtolower($manifest->type->name),
                'status' => match (true) {
                    $resolved->isRunning($manifest->id) => 'enabled',
                    isset($resolved->broken[$manifest->id]) => 'broken',
                    default => 'disabled',
                },
            ], array_values($this->installed->all())),
            'theme' => $this->attempt(fn (): ?string => $this->themes->active()?->id),
            'migrations' => [
                'executed' => $this->attempt(fn (): int => \count($this->migrations->getMetadataStorage()->getExecutedMigrations()->getItems())),
                'pending' => $this->attempt(fn (): int => \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations())),
            ],
            'health' => $this->health(),
        ];
    }

    /**
     * @return array{status: string, checks: array<string, array{status: string, message?: string}>}
     */
    private function health(): array
    {
        $healthy = true;
        $checks = [];

        foreach ($this->checks as $check) {
            $status = $check->check();
            $healthy = $healthy && $status->healthy;
            $checks[$check->name()] = ['status' => $status->healthy ? 'ok' : 'fail'] + (null === $status->message ? [] : ['message' => $status->message]);
        }

        return ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks];
    }

    /**
     * What needs the database, or null when it can't be read (the health checks say why).
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T|null
     */
    private function attempt(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            return null;
        }
    }
}
