<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use Quire\Health\HealthCheckInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-readable health report for container orchestrators and uptime monitors.
 *
 * Failure details are limited to exception class names so the endpoint never leaks
 * connection strings or credentials.
 */
#[AsController]
final readonly class HealthController
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('quire.health_check')]
        private iterable $checks,
    ) {
    }

    #[Route('/_quire/health', name: 'quire_health', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        $healthy = true;
        $report = [];

        foreach ($this->checks as $check) {
            $status = $check->check();
            $healthy = $healthy && $status->healthy;
            $report[$check->name()] = array_filter(
                ['status' => $status->healthy ? 'ok' : 'fail', 'message' => $status->message],
                static fn (?string $value): bool => null !== $value,
            );
        }

        $response = new JsonResponse(
            ['status' => $healthy ? 'ok' : 'fail', 'checks' => $report],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
