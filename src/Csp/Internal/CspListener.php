<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Quire\Csp\Csp;
use Quire\Csp\PolicyKind;
use Quire\Extend\Routes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives each response of Quire, its extensions and the site its one Content-Security-Policy. An
 * application's own pages (Quire embedded in another Symfony application) are left as they are.
 *
 * @internal
 */
final readonly class CspListener
{
    /**
     * Route default of Quire's routes ({@see \Quire\Routing\ExtensionRouteLoader::QUIRE_ROUTE}; a test
     * keeps them equal): Csp uses only Http, Extend and Settings.
     */
    public const string QUIRE_ROUTE = '_quire';

    public function __construct(
        private Csp $csp,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * After what adds to the page (the "View as" and theme preview bars, at 64 and -64), before the
     * Symfony profiler's toolbar (-128), which adds its nonces to the policy it finds.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -96)]
    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->attributes->getBoolean(self::QUIRE_ROUTE)) {
            return;
        }

        $response = $event->getResponse();
        $kind = $this->kind($request, $response);

        if (!$kind instanceof PolicyKind) {
            return;
        }

        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $header) {
            if ($response->headers->has($header)) {
                // Two policies would both apply: the stricter parts of each, which nobody meant.
                $this->logger->warning('A response of "{route}" had its own {header}, replaced by Quire\'s: Quire sends the only policy (see docs/security.md).', [
                    'route' => $request->attributes->get('_route'),
                    'header' => $header,
                ]);
                $response->headers->remove($header);
            }
        }

        if (\in_array($kind, [PolicyKind::Page, PolicyKind::Private], true) && $this->csp->loadsOnlyFromThisSite($kind)) {
            $this->dropCrossSiteHints($request, $response);
        }

        $policy = $this->csp->policy($kind);
        $hashes = $request->attributes->get(Csp::HASHES_ATTRIBUTE);

        if (\is_array($hashes) && [] !== $hashes && $policy->has('script-src')) {
            $policy = $policy->with('script-src', ...array_filter($hashes, \is_string(...)));
        }

        $nonce = $request->attributes->get(Csp::NONCE_ATTRIBUTE);

        if (\is_string($nonce)) {
            foreach (['script-src', 'style-src'] as $directive) {
                // Only where the policy limits them: adding a nonce to a directive it doesn't have would.
                if ($policy->has($directive)) {
                    $policy = $policy->with($directive, \sprintf("'nonce-%s'", $nonce));
                }
            }

            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        if ($this->csp->reportOnly() && \in_array($kind, [PolicyKind::Page, PolicyKind::Private], true)) {
            $response->headers->set('Content-Security-Policy-Report-Only', $policy->header());

            if (PolicyKind::Private === $kind) {
                // Trying a policy out never lets a password form be framed.
                $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
            }

            return;
        }

        $response->headers->set('Content-Security-Policy', $policy->header());
    }

    /**
     * A page whose policy lets it load only from this site (Csp::loadsOnlyFromThisSite(), which a
     * theme may say so by) never makes the browser connect elsewhere through a Link header either: a
     * cross-site preconnect, dns-prefetch, prefetch, preload or modulepreload, which policies don't
     * reliably govern, is dropped, and whoever added it is told in the log.
     */
    private function dropCrossSiteHints(Request $request, Response $response): void
    {
        $values = $response->headers->all('link');

        if ([] === $values) {
            return;
        }
        $kept = [];

        foreach ($values as $value) {
            foreach ($this->linkValues((string) $value) as $link) {
                if (1 === preg_match('/;\s*rel\s*=\s*"?([^";,]+)/i', $link, $rel)
                    && [] !== array_intersect(explode(' ', (string) preg_replace('/\s+/', ' ', strtolower(trim($rel[1])))), ['preconnect', 'dns-prefetch', 'prefetch', 'preload', 'modulepreload'])
                    && 1 === preg_match('/^\s*<([^>]*)>/', $link, $target)
                    && $this->otherSite($target[1], $request)
                ) {
                    $this->logger->warning('A Link header of "{route}" made the browser connect to another site ({link}), on a page that loads only from this site: dropped. Serve the file from the site, or declare the service through quire/privacy.', [
                        'route' => $request->attributes->get('_route'),
                        'link' => trim($link),
                    ]);

                    continue;
                }
                $kept[] = trim($link);
            }
        }

        $response->headers->remove('link');

        if ([] !== $kept) {
            $response->headers->set('Link', implode(', ', $kept));
        }
    }

    /**
     * The links of one Link header: split on the commas between them, not in their <URL>.
     *
     * @return list<string>
     */
    private function linkValues(string $header): array
    {
        preg_match_all('/<[^>]*>[^,<]*/', $header, $matches);

        return array_values(array_filter(array_map(trim(...), $matches[0]), static fn (string $link): bool => '' !== $link));
    }

    /**
     * Whether a hint's target may be another site. Allowed only: a path on the site (exactly one "/",
     * then neither "/" nor "\"), or an absolute http(s) URL of the request's own host, without spaces,
     * tabs, line breaks or backslashes. Anything else counts as another site, since browsers read
     * "\\host" and "/\host" as other sites and drop tabs and line breaks from URLs.
     */
    private function otherSite(string $url, Request $request): bool
    {
        if (1 === preg_match('/[\x00-\x20\\\\]/', $url)) {
            return true;
        }
        if (1 === preg_match('#^/[^/]#', $url)) {
            return false;
        }
        if (1 !== preg_match('#^https?://#i', $url)) {
            return true;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return !\is_string($host) || strtolower($host) !== strtolower($request->getHost());
    }

    private function kind(Request $request, Response $response): ?PolicyKind
    {
        $named = $request->attributes->get(Csp::ATTRIBUTE);

        if (null !== $named) {
            $kind = \is_string($named) ? PolicyKind::tryFrom($named) : null;

            if (null === $kind) {
                $route = $request->attributes->get('_route');

                throw new \LogicException(\sprintf('The route "%s" asks for the Content-Security-Policy "%s". Policies: %s.', \is_string($route) ? $route : '?', \is_string($named) ? $named : get_debug_type($named), implode(', ', array_map(static fn (PolicyKind $kind): string => $kind->value, PolicyKind::cases()))));
            }

            return $kind;
        }

        // Pages only: JSON, feeds and files need no policy of their own.
        if (!str_contains($response->headers->get('Content-Type', 'text/html'), 'html')) {
            return null;
        }

        return Routes::ADMIN === $request->attributes->get(Routes::GROUP_ATTRIBUTE) ? PolicyKind::Admin : PolicyKind::Page;
    }
}
