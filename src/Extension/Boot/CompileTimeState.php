<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Quire\Extension\State\DatabaseExtensionStateStore;

/**
 * Reads the enabled extensions while the service container is being built.
 *
 * The container doesn't exist yet at that point, so this connects with DATABASE_URL directly. When the
 * database can't be reached (e.g. while a Docker image is built), the state is reported as unavailable
 * and the container is built without extensions; the container is rebuilt once the database is up.
 *
 * @internal
 */
final readonly class CompileTimeState
{
    public const string ENV_VAR = 'DATABASE_URL';

    /**
     * @param list<string> $enabledIds
     */
    private function __construct(
        public array $enabledIds,
        public bool $available,
    ) {
    }

    public static function read(): self
    {
        $url = $_SERVER[self::ENV_VAR] ?? $_ENV[self::ENV_VAR] ?? getenv(self::ENV_VAR);

        if (!\is_string($url) || '' === $url) {
            return new self([], false);
        }

        try {
            $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']);
            $connection = DriverManager::getConnection($parser->parse($url));

            try {
                return new self(new DatabaseExtensionStateStore($connection)->enabledIds(), true);
            } finally {
                $connection->close();
            }
        } catch (\Throwable) {
            return new self([], false);
        }
    }

    /**
     * Fingerprint of this state, to detect changes.
     */
    public function hash(): string
    {
        return $this->available ? self::hashOf($this->enabledIds) : 'unavailable';
    }

    /**
     * @param list<string> $enabledIds Sorted
     */
    public static function hashOf(array $enabledIds): string
    {
        return hash('xxh128', implode(',', $enabledIds));
    }
}
