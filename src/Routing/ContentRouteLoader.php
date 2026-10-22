<?php

declare(strict_types=1);

namespace Quire\Routing;

use Quire\Content\Permalink\Permalinks;
use Quire\Http\Controller\ContentController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the routes of the homepage and of content URLs, under the "quire.routing.prefix" option.
 *
 * Content URLs come from settings and change at runtime, so one route catches every path and
 * {@see ContentController} resolves it. Both routes have the lowest priority: any other route of the
 * application or an extension matches first, wherever it's imported.
 *
 * @internal
 */
final class ContentRouteLoader extends Loader
{
    public const string TYPE = 'quire_content';

    public const int PRIORITY = -1000;

    private bool $loaded = false;

    public function __construct(
        #[Autowire(param: 'quire.routing.prefix')]
        private readonly string $prefix,
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

        $collection->add(Permalinks::HOME_ROUTE, new Route(
            path: '' === $this->prefix ? '/' : $this->prefix,
            defaults: ['_controller' => ContentController::class.'::home', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY);

        $collection->add(Permalinks::CONTENT_ROUTE, new Route(
            path: $this->prefix.'/{path}',
            defaults: ['_controller' => ContentController::class.'::show', ExtensionRouteLoader::QUIRE_ROUTE => true],
            requirements: ['path' => '.+'],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY);

        // Rebuild the route cache in development when the prefix changes.
        $collection->addResource(new ContainerParametersResource(['quire.routing.prefix' => $this->prefix]));

        return $collection;
    }
}
