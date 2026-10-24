<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;
use Quire\Content\Permalink\PermalinkResolver;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Permalink\Resolution;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Links to pages of the site lead somewhere visitors can go: not to an item that's a draft, in the trash
 * or gone, nor to a page that doesn't exist. Links to other sites aren't followed.
 *
 * @internal
 */
final readonly class LinksCheck implements ContentCheckInterface
{
    public function __construct(
        private PermalinkResolver $resolver,
        private RouterInterface $router,
    ) {
    }

    public function check(Content $item): iterable
    {
        $checked = [];

        foreach (Markdown::document($item)?->iterator() ?? [] as $node) {
            if (!$node instanceof Link) {
                continue;
            }

            $path = $this->sitePath($node->getUrl());

            if (null === $path || isset($checked[$path])) {
                continue;
            }

            $checked[$path] = true;
            $problem = $this->problem($path);

            if (null !== $problem) {
                yield new Finding($problem, 'body', Markdown::line($node));
            }
        }
    }

    /**
     * The path of a link to this site, without its query and fragment; null for other sites, anchors,
     * email addresses and the like.
     */
    private function sitePath(string $url): ?string
    {
        $parts = parse_url($url);

        if (false === $parts || !isset($parts['path']) || '' === $parts['path']) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (isset($parts['scheme']) && !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (null !== $host && strtolower($host) !== strtolower($this->router->getContext()->getHost())) {
            return null;
        }

        // Relative paths ("other-post") depend on where the page is; only paths from the root are checked.
        return str_starts_with($parts['path'], '/') ? $parts['path'] : null;
    }

    private function problem(string $path): ?string
    {
        // As a visitor's browser follows a link, whatever request runs the check (e.g. a form's POST).
        $context = $this->router->getContext();
        $method = $context->getMethod();
        $context->setMethod('GET');

        try {
            $match = $this->router->match($path);
        } catch (ExceptionInterface) {
            return \sprintf('The link to “%s” leads to no page.', $path);
        } finally {
            $context->setMethod($method);
        }

        // Pages that aren't content, e.g. the sign-in page or an extension's, exist.
        if (Permalinks::CONTENT_ROUTE !== ($match['_route'] ?? null)) {
            return null;
        }

        $relative = \is_string($match['path'] ?? null) ? '/'.$match['path'] : $path;
        $resolution = $this->resolver->resolve($relative);

        return match (true) {
            !$resolution instanceof Resolution => \sprintf('The link to “%s” leads to no page: the item may be a draft, in the trash, or gone.', $path),
            Resolution::CONTENT === $resolution->kind && $resolution->content instanceof Content && !$resolution->content->isPublic() => \sprintf('The link to “%s” leads to “%s”, which visitors can\'t see yet (%s).', $path, $resolution->content->title, $resolution->content->status),
            default => null,
        };
    }
}
