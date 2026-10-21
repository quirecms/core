<?php

declare(strict_types=1);

namespace Quire\Extension;

use Quire\Extension\Exception\ExtensionDiscoveryException;
use Quire\Extension\Exception\UnknownExtensionException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Quire extensions and themes installed through Composer.
 *
 * Installed doesn't mean enabled: this only reports what's in vendor/.
 *
 * @api
 */
final class InstalledExtensions implements ResetInterface
{
    /** @var array<string, ExtensionManifest>|null */
    private ?array $manifests = null;

    public function __construct(
        private readonly string $installedJsonPath,
        private readonly ManifestParser $parser = new ManifestParser(),
    ) {
    }

    /**
     * @return array<string, ExtensionManifest> Keyed and sorted by extension ID
     */
    public function all(): array
    {
        return $this->manifests ??= $this->discover();
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /**
     * @throws UnknownExtensionException
     */
    public function get(string $id): ExtensionManifest
    {
        return $this->all()[$id] ?? throw UnknownExtensionException::forId($id, array_keys($this->all()));
    }

    /**
     * Forgets the cached list, so long-running workers notice packages installed since the last request.
     */
    public function reset(): void
    {
        $this->manifests = null;
    }

    /**
     * @return array<string, ExtensionManifest>
     */
    private function discover(): array
    {
        $manifests = [];

        foreach ($this->packages() as $package) {
            if (!\is_array($package)) {
                continue;
            }

            $manifest = $this->parser->parse($package, \dirname($this->installedJsonPath));

            if (!$manifest instanceof ExtensionManifest) {
                continue;
            }

            if (isset($manifests[$manifest->id])) {
                throw new ExtensionDiscoveryException(\sprintf('The packages "%s" and "%s" both use the extension ID "%s". Extension IDs must be unique; set a different "extra.quire.id" in one of them.', $manifests[$manifest->id]->packageName, $manifest->packageName, $manifest->id));
            }

            $manifests[$manifest->id] = $manifest;
        }

        ksort($manifests);

        return $manifests;
    }

    /**
     * @return array<mixed>
     */
    private function packages(): array
    {
        $json = @file_get_contents($this->installedJsonPath);

        if (false === $json) {
            throw new ExtensionDiscoveryException(\sprintf('Can\'t read "%s". Run "composer install".', $this->installedJsonPath));
        }

        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtensionDiscoveryException(\sprintf('"%s" is not valid JSON. Run "composer install" to regenerate it.', $this->installedJsonPath), $e->getCode(), previous: $e);
        }

        if (!\is_array($data) || !\is_array($data['packages'] ?? null)) {
            throw new ExtensionDiscoveryException(\sprintf('"%s" has an unexpected format. Quire requires Composer 2.', $this->installedJsonPath));
        }

        return $data['packages'];
    }
}
