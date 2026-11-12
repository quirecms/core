<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * An empty JavaScript module from the site itself, for importmap entries AssetMapper writes as
 * "data:" URLs (a stylesheet a script imports), which the site's Content-Security-Policy refuses.
 *
 * @internal
 */
final class EmptyModuleController
{
    public const string ROUTE = 'quire_empty_module';

    public function __invoke(): Response
    {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'text/javascript; charset=utf-8']);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
