<?php

declare(strict_types=1);

namespace Quire\Routing;

use Quire\Extend\Routes;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the routes registered by extensions through {@see Routes}.
 *
 * Import it with the "quire_extensions" type. Routes are added in boot order, so a later extension (or
 * the site) can replace an earlier route by reusing its name.
 *
 * @internal
 */
final class ExtensionRouteLoader extends Loader
{
    public const string TYPE = 'quire_extensions';

    /** Route default marking the pages of Quire, its extensions and the site, as opposed to an application's own */
    public const string QUIRE_ROUTE = '_quire';

    /** Route default naming the route's group, e.g. "admin" (see {@see Routes}) */
    public const string GROUP = '_quire_group';

    /** @internal */
    public const string ADMIN_PREFIX_PARAMETER = 'quire.routing.admin_prefix';

    private bool $loaded = false;

    /**
     * @param list<array<string, mixed>> $routes Collected by {@see Routes}
     * @param list<string>               $files  extend.php files that were (or could be) loaded
     */
    public function __construct(
        #[Autowire(param: 'quire.extension_routes')]
        private readonly array $routes,
        #[Autowire(param: 'quire.extensions.extend_files')]
        private readonly array $files,
        #[Autowire(param: self::ADMIN_PREFIX_PARAMETER)]
        private readonly string $adminPrefix = '/admin',
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
            $group = \is_string($route['group'] ?? null) ? $route['group'] : Routes::SITE;
            $groupPrefix = Routes::ADMIN === $group ? $this->adminPrefix : '';

            if ('import' === $route['type']) {
                $imported = $this->import($route['resource'], 'attribute');

                if ($imported instanceof RouteCollection) {
                    $imported->addPrefix($groupPrefix.(\is_string($route['prefix'] ?? null) ? $route['prefix'] : ''));
                    $imported->addDefaults([self::QUIRE_ROUTE => true, self::GROUP => $group]);
                    $collection->addCollection($imported);
                }

                continue;
            }

            /** @var array{name: string, path: string, controller: string, methods: list<string>, requirements: array<string, string>, defaults: array<string, mixed>} $definition Validated by Extend\Routes */
            $definition = $route;

            $collection->add($definition['name'], new Route(
                // "/" in the admin group is the admin's own path, e.g. "/admin", not "/admin/".
                path: '' !== $groupPrefix && '/' === $definition['path'] ? $groupPrefix : $groupPrefix.$definition['path'],
                defaults: ['_controller' => $definition['controller'], ...$definition['defaults'], self::QUIRE_ROUTE => true, self::GROUP => $group],
                requirements: $definition['requirements'],
                methods: $definition['methods'],
            ));
        }

        $collection->addResource(new ContainerParametersResource([self::ADMIN_PREFIX_PARAMETER => $this->adminPrefix]));

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
