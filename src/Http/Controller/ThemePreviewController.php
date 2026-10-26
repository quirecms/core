<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Quire\Content\Permalink\Permalinks;
use Quire\Theme\ThemePreview;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Starts and stops previewing a theme (see {@see ThemePreview}), from a form that posts the theme's ID,
 * a CSRF token for "quire_theme_preview", and where to go (the homepage by default).
 *
 * @internal
 */
#[AsController]
final readonly class ThemePreviewController
{
    public const string START_ROUTE = 'quire_theme_preview';

    public const string STOP_ROUTE = 'quire_theme_preview_stop';

    public const string CSRF_TOKEN = 'quire_theme_preview';

    public function __construct(
        private ThemePreview $preview,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function start(Request $request): RedirectResponse
    {
        $this->assertToken($request);

        try {
            $this->preview->start($request->request->getString('theme'));
        } catch (\LogicException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $this->back($request);
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $this->preview->stop();

        return $this->back($request);
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Go back, reload the page and try again.');
        }
    }

    private function back(Request $request): RedirectResponse
    {
        $back = $request->request->getString('back');
        $onSite = str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\');

        return new RedirectResponse($onSite ? $back : $this->urls->generate(Permalinks::HOME_ROUTE), Response::HTTP_SEE_OTHER);
    }
}
