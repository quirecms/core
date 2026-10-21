<?php

declare(strict_types=1);

namespace Quire\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the routes registered by extensions through {@see \Quire\Extend\Routes}.
 *
 * Import it with the "quire_extensions" type. Routes are added in boot order, so a later extension (or
 * the site) can replace an earlier route by reusing its name.
 *
 * @internal
 */
final class ExtensionRouteLoader extends Loader
{
    public const string TYPE = 'quire_extensions';

    private bool $loaded = false;

    /**
     * @param list<array<string, mixed>> $routes Collected by {@see \Quire\Extend\Routes}
     * @param list<string>               $files  extend.php files that were (or could be) loaded
     */
    public function __construct(
        #[Autowire(param: 'quire.extension_routes')]
        private readonly array $routes,
        #[Autowire(param: 'quire.extensions.extend_files')]
        private readonly array $files,
        #[Autowire(param: 'kernel.environment')]
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException(\sprintf('Routes of type "%s" are imported twice. Import them only once.', self::TYPE));
        }

        $this->loaded = true;
        $collection = new RouteCollection();

        foreach ($this->routes as $route) {
            if ('import' === $route['type']) {
                $imported = $this->import($route['resource'], 'attribute');

                if ($imported instanceof RouteCollection) {
                    $imported->addPrefix(\is_string($route['prefix'] ?? null) ? $route['prefix'] : '');
                    $collection->addCollection($imported);
                }

                continue;
            }

            /** @var array{name: string, path: string, controller: string, methods: list<string>, requirements: array<string, string>, defaults: array<string, mixed>} $definition Validated by Extend\Routes */
            $definition = $route;

            $collection->add($definition['name'], new Route(
                path: $definition['path'],
                defaults: ['_controller' => $definition['controller'], ...$definition['defaults']],
                requirements: $definition['requirements'],
                methods: $definition['methods'],
            ));
        }

        // Rebuild the route cache in development when an extend.php is added, removed or edited.
        foreach ($this->files as $file) {
            $collection->addResource(new FileExistenceResource($file));

            if (is_file($file)) {
                $collection->addResource(new \Symfony\Component\Config\Resource\FileResource($file));
            }
        }

        return $collection;
    }
}
