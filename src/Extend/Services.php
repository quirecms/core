<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Registers services with Symfony's dependency injection container.
 *
 *     (new Extend\Services())
 *         ->autoload('Acme\\Reviews\\', __DIR__.'/src', exclude: [__DIR__.'/src/Entity'])
 *         ->load(__DIR__.'/config/services.php');
 *
 * Autoloaded services are autowired and autoconfigured, so attributes like `#[AsCommand]`,
 * `#[AsEventListener]` and `#[AsTwigFilter]` work without further setup.
 *
 * @api
 */
final class Services implements ExtenderInterface
{
    /** @var list<array{namespace: string, directory: string, exclude: list<string>}> */
    private array $autoload = [];

    /** @var list<string> */
    private array $files = [];

    /** @var list<array{pass: CompilerPassInterface, type: string, priority: int}> */
    private array $passes = [];

    /**
     * Registers every class in a directory as a service.
     *
     * @param string       $namespace Namespace of the classes in $directory, ending with a backslash
     * @param list<string> $exclude   Files or directories to skip, e.g. entities and value objects
     */
    public function autoload(string $namespace, string $directory, array $exclude = []): self
    {
        $this->autoload[] = ['namespace' => $namespace, 'directory' => $directory, 'exclude' => $exclude];

        return $this;
    }

    /**
     * Loads a Symfony service configuration file (`.php` or `.yaml`).
     */
    public function load(string $file): self
    {
        $this->files[] = $file;

        return $this;
    }

    /**
     * Adds a compiler pass, for advanced container manipulation.
     */
    public function compilerPass(CompilerPassInterface $pass, string $type = PassConfig::TYPE_BEFORE_OPTIMIZATION, int $priority = 0): self
    {
        $this->passes[] = ['pass' => $pass, 'type' => $type, 'priority' => $priority];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();

        foreach ($this->autoload as $autoload) {
            $where = \sprintf('Extend\Services::autoload("%s")', $autoload['namespace']);

            if (!str_ends_with($autoload['namespace'], '\\')) {
                throw $context->error($where, 'The namespace must end with a backslash.');
            }

            if (!is_dir($autoload['directory'])) {
                throw $context->error($where, \sprintf('The directory "%s" doesn\'t exist.', $autoload['directory']));
            }

            $prototype = new Definition()->setAutowired(true)->setAutoconfigured(true);
            $loader = new PhpFileLoader($container, new FileLocator($autoload['directory']));
            $loader->registerClasses($prototype, $autoload['namespace'], rtrim($autoload['directory'], '/').'/', $autoload['exclude']);
        }

        foreach ($this->files as $file) {
            $where = \sprintf('Extend\Services::load("%s")', $file);

            if (!is_file($file)) {
                throw $context->error($where, 'The file doesn\'t exist.');
            }

            $locator = new FileLocator(\dirname($file));
            $environment = $container->getParameterBag()->all()['kernel.environment'] ?? null;
            $environment = \is_string($environment) ? $environment : null;

            $loader = match (pathinfo($file, \PATHINFO_EXTENSION)) {
                'php' => new PhpFileLoader($container, $locator, $environment),
                'yaml', 'yml' => new YamlFileLoader($container, $locator, $environment),
                default => throw $context->error($where, 'Only .php and .yaml service files are supported.'),
            };

            $loader->load(basename($file));
        }

        foreach ($this->passes as $pass) {
            $container->addCompilerPass($pass['pass'], $pass['type'], $pass['priority']);
        }
    }
}
