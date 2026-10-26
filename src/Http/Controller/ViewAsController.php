<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Quire\Content\Permalink\Permalinks;
use Quire\Permission\ViewAs;
use Quire\User\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Starts and stops seeing the site as another user (see {@see ViewAs}), from a form that posts the
 * user's ID, a CSRF token for "quire_view_as", and where to go back to.
 *
 * @internal
 */
#[AsController]
final readonly class ViewAsController
{
    public const string START_ROUTE = 'quire_view_as';

    public const string STOP_ROUTE = 'quire_view_as_stop';

    public const string CSRF_TOKEN = 'quire_view_as';

    public function __construct(
        private ViewAs $viewAs,
        private UserRepository $users,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function start(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $target = $this->users->find($request->request->getString('user')) ?? throw new NotFoundHttpException('No such user.');
        $refusal = $this->viewAs->refusal($target);

        if (null !== $refusal) {
            throw new AccessDeniedHttpException($refusal);
        }

        $this->viewAs->start($target);

        return $this->back($request);
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->assertToken($request);
        $this->viewAs->stop();

        return $this->back($request);
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Go back, reload the page and try again.');
        }
    }

    /**
     * To the page the form came from, on this site only.
     */
    private function back(Request $request): RedirectResponse
    {
        $back = $request->request->getString('back');
        $onSite = str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_contains($back, '\\');

        return new RedirectResponse($onSite ? $back : $this->urls->generate(Permalinks::HOME_ROUTE), Response::HTTP_SEE_OTHER);
    }
}
