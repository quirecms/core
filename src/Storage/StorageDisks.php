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

    /** @internal Service locator of the FilesystemOperator services that "service" disks use */
    public const string SERVICES_LOCATOR = 'quire.storage.disk_services';

    /** @var array<string, Disk> Disks are made on first use, then kept: they hold no request state */
    private array $disks = [];

    /**
     * @param array<string, array<mixed>> $config
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        private readonly array $config,
        private readonly DiskFactory $factory,
        #[Autowire(service: self::SERVICES_LOCATOR)]
        private readonly ContainerInterface $services,
        #[Autowire(param: self::ROOT_PARAMETER)]
        private readonly string $root = 'storage',
    ) {
    }

    /**
     * These disks, plus those of extensions that aren't enabled yet, where they'd be without
     * configuration: e.g. for a restore of a backup that enables them.
     *
     * @internal
     *
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declarations
     */
    public function withDeclarations(array $declarations): self
    {
        $config = $this->config;

        foreach ($declarations as $disk) {
            $config[$disk['name']] ??= [...$disk, 'adapter' => 'local', 'path' => DiskConfig::defaultPath($this->root, $disk['public'], $disk['name'])];
        }

        ksort($config);

        return new self($config, $this->factory, $this->services, $this->root);
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
