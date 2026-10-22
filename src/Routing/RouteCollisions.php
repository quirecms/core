<?php

declare(strict_types=1);

namespace Quire\Routing;

use Quire\Content\Permalink\Permalinks;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds routes that take URLs Quire's content would have. Content URLs match last, so a route of the
 * application, an extension or Quire itself (e.g. /login) at the same path wins, and the item can't be
 * reached there.
 *
 * @internal
 */
final readonly class RouteCollisions
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    /**
     * The name of the route a visit to the URL reaches instead of Quire's content, if any.
     *
     * @param string $url An absolute path, as the router generates it, e.g. "/blog/hello"
     */
    public function routeTaking(string $url): ?string
    {
        $context = $this->router->getContext();
        $path = substr($url, \strlen($context->getBaseUrl()));
        $path = '' === $path ? '/' : $path;
        $this->router->setContext(new RequestContext($context->getBaseUrl(), 'GET', $context->getHost(), $context->getScheme(), $context->getHttpPort(), $context->getHttpsPort(), $path));

        try {
            $route = $this->router->match($path)['_route'] ?? null;
        } catch (ExceptionInterface) {
            return null;
        } finally {
            $this->router->setContext($context);
        }

        return \is_string($route) && !\in_array($route, [Permalinks::CONTENT_ROUTE, Permalinks::HOME_ROUTE], true) ? $route : null;
    }
}
