<?php

declare(strict_types=1);

namespace Quire\Backup;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Quire\Backup\Exception\BackupException;
use Quire\Backup\Internal\PostgresTools;
use Quire\Backup\Internal\RestoreChecks;
use Quire\Content\ContentRenderer;
use Quire\Database\DatabaseSchema;
use Quire\Database\MigrationStorage;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backs up and restores Quire's data: everything in the "quire" schema, and nothing of an application
 * that shares the database.
 *
 * A backup is a pg_dump archive in the custom format, so PostgreSQL's own tools can read it too. It
 * includes the migration history and the enabled extensions, which tell a restore what code it needs.
 *
 * @api
 */
final readonly class Backups
{
    public function __construct(
        private Connection $connection,
        private PostgresTools $tools,
        private RestoreChecks $checks,
        private AppRebuilderInterface $rebuilder,
        #[Autowire(service: ContentRenderer::CACHE_POOL)]
        private CacheItemPoolInterface $renderCache,
    ) {
    }

    /**
     * Writes a backup to the file. The file appears only once the backup is complete, and only its owner
     * can read it.
     *
     * @throws BackupException
     */
    public function create(string $file): void
    {
        $this->assertSchemaExists();

        if (file_exists($file)) {
            throw new BackupException(\sprintf('"%s" already exists. Choose another file name.', $file));
        }

        $partial = $file.'.partial';

        if (false === @file_put_contents($partial, '') || !chmod($partial, 0o600)) {
            throw new BackupException(\sprintf('Can\'t write to "%s": %s', $partial, error_get_last()['message'] ?? 'unknown error'));
        }

        try {
            $this->tools->run('pg_dump', [...$this->dumpArguments(), '--file='.$partial]);
        } catch (BackupException $e) {
            @unlink($partial);

            throw $e;
        }

        rename($partial, $file);
    }

    /**
     * Writes a backup as it's made, e.g. to standard output for another program to store.
     *
     * @param \Closure(string): void $output
     *
     * @throws BackupException
     */
    public function stream(\Closure $output): void
    {
        $this->assertSchemaExists();
        $this->tools->run('pg_dump', $this->dumpArguments(), $output);
    }

    /**
     * Reads what a backup holds, without restoring it.
     *
     * @throws BackupException when the file isn't a backup of Quire's data
     */
    public function inspect(string $file): BackupContents
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new BackupException(\sprintf('Can\'t read "%s".', $file));
        }

        try {
            $list = $this->tools->run('pg_restore', ['--list', $file]);
        } catch (BackupException $e) {
            throw new BackupException(\sprintf('"%s" isn\'t a backup made by quire:backup or pg_dump --format=custom. %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        $tables = $this->copyData($this->tools->run('pg_restore', ['--data-only', '--schema='.DatabaseSchema::NAME, '--table=migration_versions', '--table=extension', '--file=-', $file]));

        if (!isset($tables[MigrationStorage::TABLE])) {
            throw new BackupException(\sprintf('"%s" isn\'t a backup of Quire\'s data: it has no "%s" table. Make backups with quire:backup.', $file, MigrationStorage::TABLE));
        }

        $header = static fn (string $name): string => 1 === preg_match('/^;\s+'.preg_quote($name, '/').':?\s+(.+)$/m', $list, $match) ? trim($match[1]) : '';
        $createdAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s T', $header('Archive created at'));
        $extensions = array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($tables[DatabaseSchema::NAME.'.extension'] ?? [], static fn (array $row): bool => 't' === $row['enabled']),
        ));
        sort($extensions);

        return new BackupContents(
            createdAt: false === $createdAt ? null : $createdAt,
            database: $header('dbname'),
            serverVersion: $header('Dumped from database version'),
            tables: (int) preg_match_all('/^\d+; \d+ \d+ TABLE '.DatabaseSchema::NAME.' /m', $list),
            migrations: array_map(static fn (array $row): string => (string) $row['version'], $tables[MigrationStorage::TABLE]),
            enabledExtensions: $extensions,
        );
    }

    /**
     * @return list<string> Why the backup can't be restored into this database with this code; empty when it can
     */
    public function problems(BackupContents $backup): array
    {
        return $this->checks->problems($backup);
    }

    /**
     * How many content items the data a restore would replace has; null when there's none.
     */
    public function currentContentCount(): ?int
    {
        return $this->checks->currentContentCount();
    }

    /**
     * Replaces Quire's data with the backup's, then rebuilds the application for the extensions the backup
     * has enabled and runs the migrations the backup doesn't have yet.
     *
     * It's all or nothing: until everything has succeeded, the data it replaces is kept aside, and it's
     * put back when something fails.
     *
     * @param \Closure(string): void $output Receives progress output
     *
     * @throws BackupException when the backup can't be restored; nothing was changed then
     */
    public function restore(string $file, \Closure $output): BackupContents
    {
        $backup = $this->inspect($file);
        $problems = $this->problems($backup);

        if ([] !== $problems) {
            throw new BackupException(implode("\n", $problems));
        }

        $schema = DatabaseSchema::NAME;
        $replaced = RestoreChecks::REPLACED_SCHEMA;
        $replacing = $this->checks->schemaExists($schema);
        $rebuilt = false;

        if ($replacing) {
            $this->connection->executeStatement(\sprintf('ALTER SCHEMA %s RENAME TO %s', $schema, $replaced));
        }

        try {
            // Only objects in Quire's schema are restored, so nothing else in the database is touched.
            $this->connection->executeStatement(\sprintf('CREATE SCHEMA %s', $schema));
            $output("Restoring the data…\n");
            $this->tools->run('pg_restore', [
                '--schema='.$schema,
                '--single-transaction',
                '--exit-on-error',
                '--no-owner',
                '--no-privileges',
                '--no-password',
                '--dbname='.$this->tools->connectionString(),
                $file,
            ]);
            $this->analyze();

            $output("Rebuilding the application and running migrations…\n");
            $rebuilt = true;
            $this->rebuilder->rebuild(true, $output);
        } catch (\Throwable $e) {
            $this->connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));

            if ($replacing) {
                $this->connection->executeStatement(\sprintf('ALTER SCHEMA %s RENAME TO %s', $replaced, $schema));
            }

            if ($rebuilt) {
                $output("Rebuilding the application for the data from before…\n");
                $this->rebuilder->rebuild(false, $output);
            }

            throw new BackupException(\sprintf('The backup wasn\'t restored, and the data from before is back in place. %s', $e->getMessage()), $e->getCode(), previous: $e);
        }

        if ($replacing) {
            $this->connection->executeStatement(\sprintf('DROP SCHEMA %s CASCADE', $replaced));
        }

        // Restored items may have the version numbers of items cached before, with other content.
        $this->renderCache->clear();

        return $backup;
    }

    /**
     * @return list<string>
     */
    private function dumpArguments(): array
    {
        return ['--format=custom', '--schema='.DatabaseSchema::NAME, '--no-password', '--lock-wait-timeout=60000'];
    }

    private function assertSchemaExists(): void
    {
        if (!$this->checks->schemaExists(DatabaseSchema::NAME)) {
            throw new BackupException(\sprintf('The database has no "%s" schema, so there\'s nothing to back up. Run "bin/console quire:migrations:migrate" to set it up.', DatabaseSchema::NAME));
        }
    }

    /**
     * Restored tables have no planner statistics until autovacuum gets to them.
     */
    private function analyze(): void
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn('SELECT format(\'%I.%I\', schemaname, tablename) FROM pg_tables WHERE schemaname = ?', [DatabaseSchema::NAME]);

        foreach ($tables as $table) {
            $this->connection->executeStatement('ANALYZE '.$table);
        }
    }

    /**
     * The rows of the COPY blocks in a SQL script written by pg_restore.
     *
     * @return array<string, list<array<string, string|null>>> By qualified table name
     */
    private function copyData(string $sql): array
    {
        $tables = [];
        preg_match_all('/^COPY ([a-z0-9_.]+) \(([^)]*)\) FROM stdin;\n(.*?)^\\\\\.$/ms', $sql, $blocks, \PREG_SET_ORDER);

        foreach ($blocks as [, $table, $columns, $data]) {
            $names = array_map(trim(...), explode(',', $columns));
            $tables[$table] = [];

            foreach (explode("\n", rtrim($data, "\n")) as $line) {
                if ('' === $line) {
                    continue;
                }

                $values = array_map(
                    static fn (string $value): ?string => '\N' === $value ? null : (string) preg_replace_callback('/\\\\(.)/', static fn (array $m): string => ['t' => "\t", 'n' => "\n", 'r' => "\r"][$m[1]] ?? $m[1], $value),
                    explode("\t", $line),
                );

                if (\count($values) === \count($names)) {
                    $tables[$table][] = array_combine($names, $values);
                }
            }
        }

        return $tables;
    }
}
