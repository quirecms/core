<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\ContentRepository;
use Quire\Content\Event\ContentViewing;
use Quire\Content\Permalink\PermalinkResolver;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Permalink\Resolution;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * Serves the homepage, content items and listings at the URLs the permalink settings define.
 *
 * Its routes match after all others, so routes of the application and of extensions always win.
 *
 * @internal
 */
#[AsController]
final readonly class ContentController
{
    private const int MAX_PER_PAGE = 100;

    public function __construct(
        private PermalinkResolver $resolver,
        private Permalinks $permalinks,
        private ContentRepository $content,
        private ContentRenderer $renderer,
        private ContentTypeRegistry $types,
        private Settings $settings,
        private Environment $twig,
        private EventDispatcherInterface $dispatcher,
        private RouterInterface $router,
        private HttpKernelInterface $kernel,
        private LoggerInterface $logger,
    ) {
    }

    public function home(Request $request): Response
    {
        return match ($this->settings->getString('site.homepage')) {
            'page' => $this->homepagePage($request),
            'route' => $this->homepageRoute($request),
            default => $this->homepageListing($request),
        };
    }

    public function show(Request $request, string $path): Response
    {
        $resolution = $this->resolver->resolve('/'.$path) ?? throw new NotFoundHttpException(\sprintf('Nothing is published at "/%s".', $path));

        return match ($resolution->kind) {
            Resolution::REDIRECT => $this->redirect($request, (string) $resolution->url, $resolution->status),
            Resolution::LISTING => $this->listing($request, (string) $resolution->type, isHome: false),
            default => $this->single($request, $resolution->content ?? throw new \LogicException('A content resolution has an item.')),
        };
    }

    private function single(Request $request, Content $content): Response
    {
        $event = new ContentViewing($content, $request);
        $this->dispatcher->dispatch($event);

        if ($event->isDenied()) {
            throw new NotFoundHttpException('A listener denied viewing the item.');
        }

        $template = $this->twig->resolveTemplate([
            \sprintf('content/%s/%s.html.twig', $content->type, $content->slug),
            \sprintf('content/%s/single.html.twig', $content->type),
            'content/single.html.twig',
            '@Quire/content/single.html.twig',
        ]);

        return new Response($template->render([
            ...$event->variables(),
            'content' => $content,
            'type' => $this->types->get($content->type),
            'body' => $this->renderer->body($content),
            'canonical_url' => $this->permalinks->url($content, UrlGeneratorInterface::ABSOLUTE_URL),
            'site_title' => $this->settings->getString('site.title'),
            'site_locale' => $this->settings->getString('site.locale'),
        ]));
    }

    private function listing(Request $request, string $type, bool $isHome): Response
    {
        $page = 1;
        $requested = $request->query->get('page');

        if (null !== $requested) {
            if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $requested)) {
                throw new NotFoundHttpException('The page number isn\'t valid.');
            }

            $page = (int) $requested;

            if (1 === $page) {
                return $this->redirect($request, $this->permalinks->listingUrl($type), 301, keepQuery: false);
            }
        }

        $definition = $this->types->get($type);
        $perPage = max(1, min(self::MAX_PER_PAGE, $this->settings->getInt('quire.content.per_page')));
        $items = $this->content->query($type)
            ->locale($this->settings->getString('site.locale'))
            ->orderBy($definition->sortField, $definition->sortDirection)
            ->paginate($page, $perPage);

        if ($page > 1 && $items->isOutOfRange()) {
            throw new NotFoundHttpException(\sprintf('The list has only %d page(s).', $items->pageCount()));
        }

        $template = $this->twig->resolveTemplate([
            \sprintf('content/%s/list.html.twig', $type),
            'content/list.html.twig',
            '@Quire/content/list.html.twig',
        ]);

        return new Response($template->render([
            'type' => $definition,
            'page' => $items,
            'is_home' => $isHome,
            'canonical_url' => $this->permalinks->listingUrl($type, $page, UrlGeneratorInterface::ABSOLUTE_URL),
            'previous_url' => $items->hasPreviousPage() ? $this->permalinks->listingUrl($type, $page - 1) : null,
            'next_url' => $items->hasNextPage() ? $this->permalinks->listingUrl($type, $page + 1) : null,
            'site_title' => $this->settings->getString('site.title'),
            'site_locale' => $this->settings->getString('site.locale'),
        ]));
    }

    private function homepageListing(Request $request): Response
    {
        $type = $this->settings->getString('site.homepage_listing');

        if (!$this->types->has($type) || !$this->types->get($type)->has(Capability::LISTABLE)) {
            return $this->welcome(\sprintf('The homepage should list "%s", which isn\'t a content type with a list.', $type));
        }

        return $this->listing($request, $type, isHome: true);
    }

    private function homepagePage(Request $request): Response
    {
        $id = $this->permalinks->homepageId();
        $page = null === $id ? null : $this->content->query('page')->where('id', '=', $id)->first();

        if (!$page instanceof Content) {
            return $this->welcome('The homepage should show a page that doesn\'t exist or isn\'t published.');
        }

        return $this->single($request, $page);
    }

    private function homepageRoute(Request $request): Response
    {
        $name = $this->settings->get('site.homepage_route');

        try {
            $attributes = \is_string($name) ? $this->router->match($this->router->generate($name)) : null;
        } catch (RoutingException $e) {
            return $this->welcome(\sprintf('The homepage should show the route "%s": %s', $name, $e->getMessage()));
        }

        if (null === $attributes || \in_array($attributes['_route'] ?? null, [Permalinks::HOME_ROUTE, Permalinks::CONTENT_ROUTE], true)) {
            return $this->welcome('The homepage should show a route, but none is set.');
        }

        return $this->kernel->handle($request->duplicate(attributes: $attributes), HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * Shown when the homepage settings point nowhere, so the site still responds.
     */
    private function welcome(string $problem): Response
    {
        $this->logger->warning('Quire shows its welcome page instead of the homepage: {problem} Check the "site.homepage" settings.', ['problem' => $problem]);

        return new Response($this->twig->render('@Quire/home.html.twig', [
            'site_title' => $this->settings->getString('site.title'),
            'site_locale' => $this->settings->getString('site.locale'),
        ]));
    }

    private function redirect(Request $request, string $url, int $status, bool $keepQuery = true): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse($keepQuery && null !== $query && !str_contains($url, '?') ? $url.'?'.$query : $url, $status);
    }
}
