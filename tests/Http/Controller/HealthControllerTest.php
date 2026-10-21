<?php

declare(strict_types=1);

namespace Quire\Tests\Http\Controller;

use PHPUnit\Framework\TestCase;
use Quire\Health\HealthCheckInterface;
use Quire\Health\HealthStatus;
use Quire\Http\Controller\HealthController;

final class HealthControllerTest extends TestCase
{
    public function testAllChecksPassingReturns200(): void
    {
        $response = (new HealthController([
            $this->check('database', HealthStatus::healthy()),
            $this->check('cache', HealthStatus::healthy()),
        ]))();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['database' => ['status' => 'ok'], 'cache' => ['status' => 'ok']]],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testOneFailingCheckReturns503WithItsMessage(): void
    {
        $response = (new HealthController([
            $this->check('database', HealthStatus::unhealthy('Connection refused')),
            $this->check('cache', HealthStatus::healthy()),
        ]))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            [
                'status' => 'fail',
                'checks' => [
                    'database' => ['status' => 'fail', 'message' => 'Connection refused'],
                    'cache' => ['status' => 'ok'],
                ],
            ],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testNoChecksIsHealthy(): void
    {
        self::assertSame(200, (new HealthController([]))()->getStatusCode());
    }

    private function check(string $name, HealthStatus $status): HealthCheckInterface
    {
        return new readonly class($name, $status) implements HealthCheckInterface {
            public function __construct(
                private string $name,
                private HealthStatus $status,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function check(): HealthStatus
            {
                return $this->status;
            }
        };
    }
}
