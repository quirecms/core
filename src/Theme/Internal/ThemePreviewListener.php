<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Extend\Routes;
use Quire\Http\Controller\ThemePreviewController;
use Quire\Theme\ThemePreview;
use Quire\Theme\Themes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the site's pages with the theme someone previews (see {@see ThemePreview}): Twig looks in its
 * templates instead of the theme in use's for the request, and the page says so, with a way to stop.
 * The admin's pages are left alone.
 *
 * @internal
 */
final class ThemePreviewListener implements ResetInterface
{
    /** @var array<string, list<string>>|null Twig's paths before the preview, by namespace */
    private ?array $original = null;

    /**
     * @param list<array{id: string, path: string}> $active
     */
    public function __construct(
        private readonly ThemePreview $preview,
        #[Autowire(service: 'twig.loader.native_filesystem')]
        private readonly FilesystemLoader $loader,
        #[Autowire(param: Themes::CHAIN_PARAMETER)]
        private readonly array $active,
        #[Autowire(param: 'twig.default_path')]
        private readonly string $siteTemplates,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** After the firewall (8), which tells who looks */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || Routes::ADMIN === $event->getRequest()->attributes->get(Routes::GROUP_ATTRIBUTE) || null === $this->preview->current()) {
            return;
        }

        $this->preview->render(true);
        $chain = $this->preview->chain() ?? [];
        $this->original = [FilesystemLoader::MAIN_NAMESPACE => $this->loader->getPaths()];
        $inUse = array_map(static fn (array $theme): string => $theme['path'].\DIRECTORY_SEPARATOR.'templates', $this->active);
        $previewed = array_values(array_filter(array_map(static fn (array $theme): string => $theme['path'].\DIRECTORY_SEPARATOR.'templates', $chain), is_dir(...)));
        $paths = [];

        foreach ($this->original[FilesystemLoader::MAIN_NAMESPACE] as $path) {
            if (!\in_array($path, $inUse, true)) {
                $paths[] = $path;
            }

            // The previewed theme's templates where the site's end, as the theme in use's are.
            if (rtrim($path, '/') === rtrim($this->siteTemplates, '/')) {
                array_push($paths, ...$previewed);
            }
        }

        $this->loader->setPaths($paths);

        // "@<theme-id>/…", and the theme's own namespaces, where they aren't there already.
        $namespaces = $this->preview->namespaces();

        foreach ($chain as $theme) {
            if (is_dir($theme['path'].\DIRECTORY_SEPARATOR.'templates')) {
                $namespaces[$theme['id']] ??= $theme['path'].\DIRECTORY_SEPARATOR.'templates';
            }
        }

        foreach ($namespaces as $namespace => $directory) {
            // Twig can't take a namespace away: one emptied after an earlier preview counts as absent.
            if ([] === $this->loader->getPaths($namespace) && is_dir($directory)) {
                $this->original[$namespace] = [];
                $this->loader->setPaths([$directory], $namespace);
            }
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->original) {
            return;
        }

        $id = $this->preview->chain()[0]['id'] ?? null;
        $this->reset();

        if (null !== $id) {
            $this->addBar($event->getRequest(), $event->getResponse(), $id);
        }
    }

    public function reset(): void
    {
        foreach ($this->original ?? [] as $namespace => $paths) {
            $this->loader->setPaths($paths, $namespace);
        }

        $this->original = null;
        $this->preview->render(false);
    }

    private function addBar(Request $request, Response $response, string $theme): void
    {
        $content = $response->getContent();

        if (false === $content || $response->isRedirection() || !str_contains($response->headers->get('Content-Type', 'text/html'), 'html') || 1 !== preg_match('/<body\b[^>]*>/i', $content, $match, \PREG_OFFSET_CAPTURE)) {
            return;
        }

        $position = $match[0][1] + \strlen($match[0][0]);
        $bar = \sprintf(
            '<div class="quire-theme-preview" role="region" aria-label="%1$s" style="position:relative;z-index:2147483000;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:center;padding:.6rem 1rem;background:#1d1a24;color:#fff;font:600 15px/1.4 system-ui,sans-serif">'
            .'<span>%2$s</span>'
            .'<form method="post" action="%3$s" style="margin:0"><input type="hidden" name="_token" value="%4$s"><input type="hidden" name="back" value="%5$s">'
            .'<button type="submit" style="min-height:2.25rem;padding:.3rem 1rem;border:0;border-radius:999px;background:#f0b04a;color:#1d1a24;font:inherit;cursor:pointer">%6$s</button></form></div>',
            htmlspecialchars($this->translator->trans('Theme preview', [], 'quire')),
            htmlspecialchars($this->translator->trans('You’re previewing the theme “{theme}”: only you see it.', ['theme' => $theme], 'quire')),
            htmlspecialchars($this->urls->generate(ThemePreviewController::STOP_ROUTE)),
            htmlspecialchars($this->csrf->getToken(ThemePreviewController::CSRF_TOKEN)->getValue()),
            htmlspecialchars($request->getRequestUri()),
            htmlspecialchars($this->translator->trans('Stop the preview', [], 'quire')),
        );
        $response->setContent(substr($content, 0, $position).$bar.substr($content, $position));
    }
}
