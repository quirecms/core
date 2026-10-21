<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Placeholder homepage until the content model (M2) provides a configurable homepage.
 */
#[AsController]
final readonly class HomeController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    #[Route('/', name: 'quire_home', methods: ['GET', 'HEAD'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('@Quire/home.html.twig'));
    }
}
