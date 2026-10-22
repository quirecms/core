<?php

declare(strict_types=1);

use Quire\Routing\ContentRouteLoader;
use Quire\Routing\ExtensionRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../src/Http/Controller/', 'attribute');

    // Extension routes come after core routes, so they can replace a core route by reusing its name.
    $routes->import('.', ExtensionRouteLoader::TYPE);

    // The homepage and content URLs, which match only when no other route does.
    $routes->import('.', ContentRouteLoader::TYPE);
};
