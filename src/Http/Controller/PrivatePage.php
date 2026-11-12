<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for pages with forms for passwords and accounts.
 *
 * @internal
 */
final class PrivatePage
{
    /**
     * Never cached, and never shown inside another site, where a frame could capture what's typed: the
     * route's Content-Security-Policy says so too ("private", see Quire\Csp\PolicyKind), and
     * X-Frame-Options for older browsers.
     */
    public static function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function __construct()
    {
    }
}
