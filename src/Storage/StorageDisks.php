<?php

declare(strict_types=1);

namespace Quire\Storage;

use Psr\Container\ContainerInterface;
use Quire\Storage\Exception\UnknownDiskException;
use Quire\Storage\Internal\DiskConfig;
use Quire\Storage\Internal\DiskFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site's storage disks, by name: those extensions declare with Extend\Storage, placed where
 * "quire.storage.disks" says, and the site's own.
 *
 * @api
 */
final class StorageDisks
{
    /** @internal Parameter: array<name, resolved disk configuration> */
    public const string PARAMETER = 'quire.storage.disks';

    /** @internal */
    public const string ROOT_PARAMETER = 'quire.storage.root';

    /**
     * @internal Parameter: array<name, configuration> of disks the site placed that no enabled extension
     *           declares, e.g. a disabled extension's disk on S3: not in use, but kept for backups
     */
    public const string DORMANT_PARAMETER = 'quire.storage.dormant_disks';

    /** @internal Service locator of the FilesystemOperator services that "service" disks use */
    public const string SERVICES_LOCATOR = 'quire.storage.disk_services';

    /** @var array<string, Disk> Disks are made on first use, then kept: they hold no request state */
    private array $disks = [];

    /**
     * @param array<string, array<mixed>> $config
     * @param array<string, array<mixed>> $dormant
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        private readonly array $config,
        private readonly DiskFactory $factory,
        #[Autowire(service: self::SERVICES_LOCATOR)]
        private readonly ContainerInterface $services,
        #[Autowire(param: self::ROOT_PARAMETER)]
        private readonly string $root = 'storage',
        #[Autowire(param: self::DORMANT_PARAMETER)]
        private readonly array $dormant = [],
    ) {
    }

    /**
     * These disks, plus those of extensions that aren't enabled: where the site placed them, or where
     * they are without configuration. E.g. for a restore of a backup that enables them, or a backup that
     * reaches them.
     *
     * @internal
     *
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declarations
     */
    public function withDeclarations(array $declarations): self
    {
        $config = $this->config;
        $dormant = $this->dormant;

        foreach ($declarations as $disk) {
            if (isset($config[$disk['name']])) {
                continue;
            }

            $placed = $dormant[$disk['name']] ?? ['adapter' => 'local'];
            $path = \is_string($placed['path'] ?? null) && '' !== $placed['path'] ? $placed['path'] : DiskConfig::defaultPath($this->root, $disk['public'], $disk['name']);
            $config[$disk['name']] = [...$placed, ...$disk, 'path' => $path];
            unset($dormant[$disk['name']]);
        }

        ksort($config);

        return new self($config, $this->factory, $this->services, $this->root, $dormant);
    }

    /**
     * @internal
     *
     * @return list<string> Disks the site placed in "quire.storage.disks" that no enabled extension
     *                      declares, sorted: in use again once one is enabled that declares them
     */
    public function dormant(): array
    {
        return array_map(strval(...), array_keys($this->dormant));
    }

    /**
     * @throws UnknownDiskException
     */
    public function get(string $name): Disk
    {
        if (!isset($this->config[$name])) {
            throw UnknownDiskException::named($name, $this->names());
        }

        return $this->disks[$name] ??= $this->factory->create(DiskConfig::fromArray($name, $this->config[$name]), $this->services);
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /**
     * @return list<string> Names of all disks, sorted
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->config));
    }
}
