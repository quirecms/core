<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Quire\Database\DatabaseSchema;
use Quire\Extension\State\DatabaseExtensionStateStore;
use Quire\Theme\Themes;

/**
 * Reads the enabled extensions and the active theme while the service container is being built.
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
     * @param string|null  $activeTheme ID of the active theme; null for none
     */
    private function __construct(
        public array $enabledIds,
        public ?string $activeTheme,
        public bool $available,
    ) {
    }

    public static function read(): self
    {
        $url = $_SERVER[self::ENV_VAR] ?? $_ENV[self::ENV_VAR] ?? getenv(self::ENV_VAR);

        if (!\is_string($url) || '' === $url) {
            return new self([], null, false);
        }

        try {
            $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']);
            $connection = DriverManager::getConnection($parser->parse($url));

            try {
                return new self(new DatabaseExtensionStateStore($connection)->enabledIds(), self::activeThemeIn($connection), true);
            } finally {
                $connection->close();
            }
        } catch (\Throwable) {
            return new self([], null, false);
        }
    }

    /**
     * The active theme as stored in the database: the "theme.active" setting, or its default.
     */
    public static function activeThemeIn(Connection $connection): ?string
    {
        $row = $connection->fetchAssociative(\sprintf("SELECT value #>> '{}' AS theme FROM %s.setting WHERE name = ?", DatabaseSchema::NAME), [Themes::SETTING]);

        if (false === $row) {
            return Themes::DEFAULT;
        }

        return \is_string($row['theme']) && '' !== $row['theme'] ? $row['theme'] : null;
    }

    /**
     * Fingerprint of this state, to detect changes.
     */
    public function hash(): string
    {
        return $this->available ? self::hashOf($this->enabledIds, $this->activeTheme) : 'unavailable';
    }

    /**
     * @param list<string> $enabledIds Sorted
     */
    public static function hashOf(array $enabledIds, ?string $activeTheme): string
    {
        return hash('xxh128', implode(',', $enabledIds).'|'.$activeTheme);
    }
}
