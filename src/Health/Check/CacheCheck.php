<?php

declare(strict_types=1);

namespace Quire\Health\Check;

use Psr\Cache\CacheItemPoolInterface;
use Quire\Health\HealthCheckInterface;
use Quire\Health\HealthStatus;

final readonly class CacheCheck implements HealthCheckInterface
{
    private const string KEY = 'quire.health_check';

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function name(): string
    {
        return 'cache';
    }

    public function check(): HealthStatus
    {
        try {
            $value = bin2hex(random_bytes(8));
            $this->cache->save($this->cache->getItem(self::KEY)->set($value));

            if ($this->cache->getItem(self::KEY)->get() !== $value) {
                return HealthStatus::unhealthy('Value written to the cache could not be read back.');
            }

            $this->cache->deleteItem(self::KEY);
        } catch (\Throwable $e) {
            return HealthStatus::unhealthy($e::class);
        }

        return HealthStatus::healthy();
    }
}
