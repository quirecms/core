<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Composer\InstalledVersions;
use Doctrine\Migrations\DependencyFactory;
use Quire\Database\MigrationsFactory;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\PinnedState;
use Quire\Health\HealthCheckInterface;
use Quire\Settings\Settings;
use Quire\Storage\Internal\InstalledDisks;
use Quire\Theme\Themes;
use Quire\Translation\Internal\TranslationArtifactsPass;
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
 *     storage: list<array{name: string, adapter: string, public: ?bool, location: ?string, in_use: bool}>|null,
 *     migrations: array{executed: ?int, pending: ?int},
 *     health: array{status: string, checks: array<string, array{status: string, message?: string}>},
 *     translations: list<array{name: string, package: ?string, locale: ?string, status: string, reason: ?string, files: list<string>}>,
 *     warnings: list<string>,
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
        private InstalledDisks $disks,
        private PinnedState $pinnedState,
        #[Autowire(param: Themes::PINNED_PARAMETER)]
        private bool $pinned = false,
        /** @var list<array{name: string, package: ?string, locale: ?string, status: string, reason: ?string, files: list<string>}> */
        #[Autowire(param: TranslationArtifactsPass::ARTIFACTS)]
        private array $translations = [],
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
            // Where its stored files are, for tools that copy a site: a copy on the original's bucket
            // would change the original's files. Null when the disks can't be read (the warnings say why).
            'storage' => $this->attempt($this->storage(...)),
            'migrations' => [
                'executed' => $this->attempt(fn (): int => \count($this->migrations->getMetadataStorage()->getExecutedMigrations()->getItems())),
                'pending' => $this->attempt(fn (): int => \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations())),
            ],
            'health' => $this->health(),
            // The language packages installed, each loaded or ignored with why
            'translations' => $this->translations,
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * Every disk the site has files on: those in use, a disabled extension's (where the site placed it,
     * or where it is without configuration), and those the site placed that no extension declares.
     *
     * @return list<array{name: string, adapter: string, public: ?bool, location: ?string, in_use: bool}> By name
     */
    private function storage(): array
    {
        $all = $this->disks->all();
        $disks = [];

        foreach ($all->names() as $name) {
            $disk = $all->get($name);
            $disks[$name] = ['name' => $name, 'adapter' => $disk->adapter, 'public' => $disk->public, 'location' => $disk->location, 'in_use' => true];
        }

        foreach ($all->dormant() as $name) {
            $disks[$name] ??= ['name' => $name, 'adapter' => $all->dormantAdapter($name), 'public' => null, 'location' => null, 'in_use' => false];
        }

        ksort($disks);

        return array_values($disks);
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
     * For people managing the site: what works, but likely not as they meant it. Not in the public
     * health report, which runs no extension's code and names no configuration. Advisory, so it never
     * fails the report a server relies on.
     *
     * @return list<string>
     */
    private function warnings(): array
    {
        try {
            $warnings = array_map(
                static fn (string $disk): string => \sprintf('"quire.storage.disks.%s" places a disk that no installed extension declares, so it isn\'t used. Set its "public" to make it a disk of the site\'s own, or remove it.', $disk),
                $this->disks->placedButUndeclared(),
            );
        } catch (\Throwable $e) {
            $warnings = [\sprintf('The storage disks couldn\'t be checked (%s): run "bin/console quire:storage:list" to see them.', $e::class)];
        }

        try {
            if (!$this->pinned && [] !== $this->users->all(Role::ADMIN, 1) && $this->pinnedState->mayHaveLostPinnedState()) {
                $warnings[] = 'No extension was ever enabled on this site, not even the admin interface. If it was pinned ("quire.extensions.pinned") before Quire 0.3.1, what it ran pinned wasn\'t carried over: enable its extensions with "bin/console quire:extension:enable" and choose its theme with "bin/console quire:theme:use" (see "Stop pinning" in docs/extensions.md).';
            }
        } catch (\Throwable $e) {
            $warnings[] = \sprintf('Whether the site lost what it ran pinned couldn\'t be checked (%s).', $e::class);
        }

        foreach ($this->translations as $artifact) {
            if ('loaded' !== $artifact['status']) {
                $warnings[] = \sprintf('The language package "%s" isn\'t loaded: %s', $artifact['name'], $artifact['reason']);
            }
        }

        return $warnings;
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
