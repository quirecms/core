<?php

declare(strict_types=1);

namespace Quire\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Quire\Content\Permalink\Permalinks;
use Quire\Csp\Csp;
use Quire\Csp\PolicyKind;
use Quire\Http\Controller\LoginController;
use Quire\Routing\ContentRouteLoader;
use Quire\Routing\ExtensionRouteLoader;
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
        $routes->addCollection(new ContentRouteLoader('/cms', login: true)->load('.', ContentRouteLoader::TYPE));
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
        $routes = new ContentRouteLoader('', login: true)->load('.', ContentRouteLoader::TYPE);

        self::assertSame('/', $routes->get(Permalinks::HOME_ROUTE)?->getPath());
        self::assertSame('/{path}', $routes->get(Permalinks::CONTENT_ROUTE)?->getPath());
        self::assertSame('/login', $routes->get(LoginController::LOGIN_ROUTE)?->getPath());
    }

    public function testApplicationsThatSignInTheirUsersHaveNoSignInPage(): void
    {
        $routes = new ContentRouteLoader('', login: false)->load('.', ContentRouteLoader::TYPE);

        self::assertNull($routes->get(LoginController::LOGIN_ROUTE));
        self::assertNull($routes->get(LoginController::LOGOUT_ROUTE));
        self::assertNotNull($routes->get(Permalinks::CONTENT_ROUTE));
    }

    /**
     * The Content-Security-Policy applies to Quire's routes only, by their "_quire" default: a route
     * without it would get no policy at all.
     */
    public function testEveryRouteGetsQuiresPolicy(): void
    {
        foreach (new ContentRouteLoader('', login: true)->load('.', ContentRouteLoader::TYPE) as $name => $route) {
            self::assertTrue($route->getDefault(ExtensionRouteLoader::QUIRE_ROUTE), \sprintf('The route "%s" has no "%s" default.', $name, ExtensionRouteLoader::QUIRE_ROUTE));
        }
    }

    /**
     * Every controller that serves a form for passwords or accounts (it uses PrivatePage) asks for the
     * private policy on each of its routes: never framed, and nothing from other sites' services.
     */
    public function testPasswordFormsGetThePrivatePolicy(): void
    {
        $private = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src/Http', \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            $code = (string) file_get_contents($file->getPathname());

            if (str_contains($code, 'PrivatePage::') && 1 === preg_match('#^namespace ([^;]+);.*?^(?:final |readonly |abstract )*class (\w+)#ms', $code, $match)) {
                $private[$match[1].'\\'.$match[2]] = 0;
            }
        }

        self::assertNotEmpty($private);

        foreach (new ContentRouteLoader('', login: true)->load('.', ContentRouteLoader::TYPE) as $name => $route) {
            $controller = $route->getDefault('_controller');
            $controller = \is_string($controller) ? explode('::', $controller)[0] : '';

            if (\array_key_exists($controller, $private)) {
                ++$private[$controller];
                self::assertSame(PolicyKind::Private->value, $route->getDefault(Csp::ATTRIBUTE), \sprintf('The route "%s" serves a password form without the private policy.', $name));
            }
        }

        self::assertNotContains(0, $private, 'Each of these controllers has routes: '.implode(', ', array_keys($private)));
    }
}
