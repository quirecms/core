<?php

declare(strict_types=1);

namespace Quire\Health;

/**
 * @api
 */
final readonly class HealthStatus
{
    private function __construct(
        public bool $healthy,
        public ?string $message = null,
    ) {
    }

    public static function healthy(): self
    {
        return new self(true);
    }

    public static function unhealthy(string $message): self
    {
        return new self(false, $message);
    }
}
