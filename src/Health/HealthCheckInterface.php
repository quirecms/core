<?php

declare(strict_types=1);

namespace Quire\Health;

/**
 * A single check reported by the `/_quire/health` endpoint.
 *
 * Implementations are tagged automatically; extensions can add their own.
 *
 * @api
 */
interface HealthCheckInterface
{
    /**
     * Short, stable identifier used as the key in the health report (e.g. "database").
     */
    public function name(): string;

    public function check(): HealthStatus;
}
