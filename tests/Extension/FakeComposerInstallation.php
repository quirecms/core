<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

/**
 * Builds a throwaway vendor/ directory with a Composer installed.json, for discovery tests.
 */
final class FakeComposerInstallation
{
    private readonly string $root;

    /** @var list<array<string, mixed>> */
    private array $packages = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/quire-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/vendor/composer', 0o777, true);
    }

    public function __destruct()
    {
        $this->remove($this->root);
    }

    /**
     * Adds a package and creates its install directory.
     *
     * @param array<string, mixed> $overrides Keys of the installed.json entry to set or replace
     */
    public function addPackage(string $name, string $type = 'quire-extension', array $overrides = []): self
    {
        mkdir($this->root.'/vendor/'.$name, 0o777, true);

        $this->packages[] = [
            'name' => $name,
            'version' => '1.2.3.0',
            'pretty_version' => '1.2.3',
            'type' => $type,
            'install-path' => '../'.$name,
            ...$overrides,
        ];

        return $this;
    }

    /**
     * Writes a file inside an installed package, e.g. its extend.php.
     */
    public function addFile(string $packageName, string $relativePath, string $contents): self
    {
        $path = $this->root.'/vendor/'.$packageName.'/'.$relativePath;

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }

        file_put_contents($path, $contents);

        return $this;
    }

    /**
     * A directory outside vendor/, e.g. to act as the project root.
     */
    public function projectDir(): string
    {
        if (!is_dir($this->root.'/project')) {
            mkdir($this->root.'/project');
        }

        return (string) realpath($this->root.'/project');
    }

    public function installedJsonPath(): string
    {
        $path = $this->root.'/vendor/composer/installed.json';
        file_put_contents($path, json_encode(['packages' => $this->packages, 'dev' => true, 'dev-package-names' => []], \JSON_THROW_ON_ERROR));

        return $path;
    }

    public function vendorComposerDir(): string
    {
        return $this->root.'/vendor/composer';
    }

    public function packagePath(string $name): string
    {
        return (string) realpath($this->root.'/vendor/'.$name);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);

            foreach (false === $entries ? [] : $entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->remove($path.'/'.$entry);
                }
            }

            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
