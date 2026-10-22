<?php

declare(strict_types=1);

namespace Quire\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;

/**
 * Records which of Quire's migrations ran, in a table inside Quire's own schema.
 *
 * Keeping the record next to Quire's tables means a backup of the schema restores Quire's data together
 * with its migration state, and the application's own migrations stay separate. The table is managed
 * with plain SQL rather than Doctrine's schema tools, because those are told to ignore Quire's schema
 * (see {@see SchemaAssetFilter}).
 *
 * @internal
 */
final class MigrationStorage implements MetadataStorage
{
    public const string TABLE = DatabaseSchema::NAME.'.migration_versions';

    private bool $initialized = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly Comparator $comparator,
    ) {
    }

    public function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->connection->executeStatement(\sprintf('CREATE SCHEMA IF NOT EXISTS %s', DatabaseSchema::NAME));
        $this->connection->executeStatement(\sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                executed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                execution_time INT DEFAULT NULL
            )',
            self::TABLE,
        ));
        $this->initialized = true;
    }

    public function getExecutedMigrations(): ExecutedMigrationsList
    {
        if (!$this->initialized && null === $this->connection->fetchOne('SELECT to_regclass(?)', [self::TABLE])) {
            return new ExecutedMigrationsList([]);
        }

        /** @var list<array{version: string, executed_at: ?string, execution_time: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(\sprintf('SELECT version, executed_at, execution_time FROM %s', self::TABLE));
        $migrations = array_map(static fn (array $row): ExecutedMigration => new ExecutedMigration(
            new Version($row['version']),
            null === $row['executed_at'] ? null : new \DateTimeImmutable($row['executed_at']),
            null === $row['execution_time'] ? null : (int) $row['execution_time'] / 1000,
        ), $rows);

        usort($migrations, fn (ExecutedMigration $a, ExecutedMigration $b): int => $this->comparator->compare($a->getVersion(), $b->getVersion()));

        return new ExecutedMigrationsList($migrations);
    }

    public function complete(ExecutionResult $result): void
    {
        $this->ensureInitialized();

        if (Direction::DOWN === $result->getDirection()) {
            $this->connection->executeStatement(\sprintf('DELETE FROM %s WHERE version = ?', self::TABLE), [(string) $result->getVersion()]);

            return;
        }

        $time = $result->getTime();
        $this->connection->executeStatement(
            \sprintf('INSERT INTO %s (version, executed_at, execution_time) VALUES (?, ?, ?)', self::TABLE),
            [(string) $result->getVersion(), ($result->getExecutedAt() ?? new \DateTimeImmutable())->format(\DATE_ATOM), null === $time ? null : (int) round($time * 1000)],
        );
    }

    public function reset(): void
    {
        $this->ensureInitialized();
        $this->connection->executeStatement(\sprintf('DELETE FROM %s', self::TABLE));
    }
}
