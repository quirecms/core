<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class InvokableController
{
    public function __invoke(): Response
    {
        return new Response();
    }

    public function store(): Response
    {
        return new Response();
    }

    public function onRequest(RequestEvent $event): void
    {
    }
}
