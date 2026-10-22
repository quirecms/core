<?php

declare(strict_types=1);

namespace Quire\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Quire\Content\Permalink\Permalinks;
use Quire\Routing\ContentRouteLoader;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class ContentRouteLoaderTest extends TestCase
{
    public function testContentRoutesMatchLastUnderThePrefix(): void
    {
        $routes = new RouteCollection();
        $routes->addCollection(new ContentRouteLoader('/cms')->load('.', ContentRouteLoader::TYPE));
        // An application route added afterwards still wins, even though it looks like content.
        $routes->add('app_tournaments', new Route('/cms/tournaments'));

        $matcher = new UrlMatcher($routes, new RequestContext());
        $generator = new UrlGenerator($routes, new RequestContext(baseUrl: '/index.php'));

        self::assertSame('app_tournaments', $matcher->match('/cms/tournaments')['_route']);
        self::assertSame(Permalinks::CONTENT_ROUTE, $matcher->match('/cms/blog/hello')['_route']);
        self::assertSame('blog/hello', $matcher->match('/cms/blog/hello')['path']);
        self::assertSame(Permalinks::HOME_ROUTE, $matcher->match('/cms')['_route']);
        self::assertSame('/index.php/cms/about/team', $generator->generate(Permalinks::CONTENT_ROUTE, ['path' => 'about/team']), 'Slashes stay slashes.');
        self::assertSame('/index.php/cms', $generator->generate(Permalinks::HOME_ROUTE));
    }

    public function testWithoutAPrefixTheHomepageIsTheRoot(): void
    {
        $routes = new ContentRouteLoader('')->load('.', ContentRouteLoader::TYPE);

        self::assertSame('/', $routes->get(Permalinks::HOME_ROUTE)?->getPath());
        self::assertSame('/{path}', $routes->get(Permalinks::CONTENT_ROUTE)?->getPath());
    }
}
