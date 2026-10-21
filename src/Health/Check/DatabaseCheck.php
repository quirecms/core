<?php

declare(strict_types=1);

namespace Quire\Health\Check;

use Doctrine\DBAL\Connection;
use Quire\Health\HealthCheckInterface;
use Quire\Health\HealthStatus;

final readonly class DatabaseCheck implements HealthCheckInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthStatus
    {
        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            return HealthStatus::unhealthy($e::class);
        }

        return HealthStatus::healthy();
    }
}
