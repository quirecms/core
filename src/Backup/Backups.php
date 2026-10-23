<?php

declare(strict_types=1);

namespace Quire\Backup;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Quire\Backup\Exception\BackupException;
use Quire\Backup\Internal\PostgresTools;
use Quire\Backup\Internal\RestoreChecks;
use Quire\Backup\Internal\TarReader;
use Quire\Backup\Internal\TarWriter;
use Quire\Content\ContentRenderer;
use Quire\Content\Internal\Row;
use Quire\Database\DatabaseSchema;
use Quire\Database\MigrationStorage;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Storage\Disk;
use Quire\Storage\Internal\GarbageCollector;
use Quire\Storage\StorageDisks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Backs up and restores a site: Quire's data (everything in the "quire" schema, and nothing of an
 * application that shares the database) and the stored files.
 *
 * A backup is a tar archive of:
 *
 * - `database.dump`: a pg_dump archive in the custom format, which PostgreSQL's own tools read too. It
 *   includes the migration history and the enabled extensions, which tell a restore what code it needs,
 *   and the list of every stored file.
 * - `files/<disk>/<key>`: copies of the stored files (by default those on local disks), each checked
 *   against its checksum. Or, with a files directory, none: they're in that directory, shared by
 *   backups, so each one copies only the files that are new.
 * - `manifest.json`: what was copied, and what was missing.
 *
 * pg_dump archives made by quire:backup before it included files are still restored.
 *
 * @api
 */
final readonly class Backups
{
    private const string DUMP_ENTRY = 'database.dump';

    private const string MANIFEST_ENTRY = 'manifest.json';

    private const string FILES_PREFIX = 'files/';

    public function __construct(
        private Connection $connection,
        private PostgresTools $tools,
        private RestoreChecks $checks,
        private AppRebuilderInterface $rebuilder,
        #[Autowire(service: ContentRenderer::CACHE_POOL)]
        private CacheItemPoolInterface $renderCache,
        private StorageDisks $disks,
    ) {
    }

    /**
     * Writes a backup to the file. The file appears only once the backup is complete, and only its owner
     * can read it.
     *
     * @param string|null $filesDirectory Copy the files there instead of into the backup: a directory
     *                                    shared by backups, where each copies only the files that are new
     *
     * @throws BackupException
     */
    public function create(string $file, BackupFiles $files = BackupFiles::Local, ?string $filesDirectory = null): BackupReport
    {
        $this->assertSchemaExists();

        if (file_exists($file)) {
            throw new BackupException(\sprintf('"%s" already exists. Choose another file name.', $file));
        }

        $partial = $file.'.partial';
        $handle = @fopen($partial, 'x');

        if (false === $handle || !chmod($partial, 0o600)) {
            throw new BackupException(\sprintf('Can\'t write to "%s": %s', $partial, error_get_last()['message'] ?? 'unknown error'));
        }

        try {
            $report = $this->write(static function (string $data) use ($handle): void {
                fwrite($handle, $data);
            }, $files, $filesDirectory);
            fclose($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($partial);

            throw $e;
        }

        rename($partial, $file);

        return $report;
    }

    /**
     * Writes a backup as it's made, e.g. to standard output for another program to store.
     *
     * @param \Closure(string): void $output
     *
     * @throws BackupException
     */
    public function stream(\Closure $output, BackupFiles $files = BackupFiles::Local, ?string $filesDirectory = null): BackupReport
    {
        $this->assertSchemaExists();

        return $this->write($output, $files, $filesDirectory);
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

        if (!TarReader::isArchive($file)) {
            return $this->inspectDump($file, $file);
        }

        [$entries, $manifest] = $this->openArchive($file);
        $dump = $this->extractDump($file, $entries);

        try {
            $contents = $this->inspectDump($dump, $file);
        } finally {
            @unlink($dump);
        }

        $files = $manifest['files'] ?? null;
        $missing = array_merge((array) ($manifest['missing'] ?? []), (array) ($manifest['corrupt'] ?? []));

        return new BackupContents(
            createdAt: $contents->createdAt,
            database: $contents->database,
            serverVersion: $contents->serverVersion,
            tables: $contents->tables,
            migrations: $contents->migrations,
            enabledExtensions: $contents->enabledExtensions,
            format: BackupContents::ARCHIVE,
            files: \in_array($files, ['archive', 'directory', 'none'], true) ? $files : 'none',
            objects: $contents->objects,
            filesInArchive: \count(array_filter(array_keys($entries), static fn (string $name): bool => str_starts_with($name, self::FILES_PREFIX))),
            missingWhenMade: array_values(array_filter($missing, \is_string(...))),
        );
    }

    /**
     * @param string|null $filesDirectory Where the copies of the files are, for a backup made with one
     *
     * @return list<string> Why the backup can't be restored into this database with this code; empty when it can
     */
    public function problems(BackupContents $backup, ?string $filesDirectory = null): array
    {
        return $this->checks->problems($backup, $filesDirectory);
    }

    /**
     * How many content items the data a restore would replace has; null when there's none.
     */
    public function currentContentCount(): ?int
    {
        return $this->checks->currentContentCount();
    }

    /**
     * Restores the stored files to the site's disks, wherever they are now, then replaces Quire's data
     * with the backup's, rebuilds the application for the extensions the backup has enabled, and runs the
     * migrations the backup doesn't have yet. Finally it checks that every stored file the restored
     * database lists is on its disk.
     *
     * The database part is all or nothing: until it has succeeded, the data it replaces is kept aside,
     * and it's put back when something fails. Files are only ever added, so ones a failed restore wrote
     * are collected later by quire:storage:gc.
     *
     * @param \Closure(string): void $output Receives progress output
     *
     * @throws BackupException when the backup can't be restored; Quire's data wasn't changed then
     */
    public function restore(string $file, \Closure $output, ?string $filesDirectory = null): RestoreReport
    {
        $backup = $this->inspect($file);
        $problems = $this->problems($backup, $filesDirectory);

        if ([] !== $problems) {
            throw new BackupException(implode("\n", $problems));
        }

        $disks = $this->checks->disksAfterRestore($backup);
        $isArchive = BackupContents::ARCHIVE === $backup->format;
        $entries = $isArchive ? $this->openArchive($file)[0] : [];
        $dump = $isArchive ? $this->extractDump($file, $entries) : $file;

        try {
            $files = ['restored' => 0, 'alreadyThere' => 0, 'corrupt' => []];

            if ([] !== $backup->objects && 'none' !== $backup->files && null !== $backup->files) {
                $output("Restoring the stored files…\n");
                $files = $this->restoreFiles($disks, $this->objectsInDump($dump), new TarReader($file), $entries, 'directory' === $backup->files ? $filesDirectory : null);
            }

            $this->restoreDatabase($dump, $output);
        } finally {
            if ($isArchive) {
                @unlink($dump);
            }
        }

        // Restored items may have the version numbers of items cached before, with other content.
        $this->renderCache->clear();

        return new RestoreReport($backup, $files['restored'], $files['alreadyThere'], $files['corrupt'], $this->missingFiles($disks));
    }

    /**
     * @param \Closure(string): void $output
     */
    private function write(\Closure $output, BackupFiles $files, ?string $filesDirectory): BackupReport
    {
        if ($this->connection->isTransactionActive()) {
            throw new BackupException('Make backups outside of a database transaction: they read a snapshot of their own.');
        }

        if (null !== $filesDirectory && !is_dir($filesDirectory) && !@mkdir($filesDirectory, 0o700, true) && !is_dir($filesDirectory)) {
            throw new BackupException(\sprintf('Can\'t create the files directory "%s".', $filesDirectory));
        }

        $dump = $this->temporaryFile('quire-backup-');

        // Shared with other backups, exclusive for the garbage collector: no file the dump lists can be
        // collected before it's copied.
        $this->connection->executeStatement('SELECT pg_advisory_lock_shared(?)', [GarbageCollector::BACKUP_LOCK]);

        try {
            $objects = $this->dumpDatabase($dump);
            $tar = new TarWriter($output);
            $handle = $this->opened(fopen($dump, 'r'), 'Can\'t read the database dump.');

            try {
                $tar->addStream(self::DUMP_ENTRY, $handle, (int) filesize($dump));
            } finally {
                fclose($handle);
            }

            $report = $this->copyFiles($objects, $files, $tar, $filesDirectory);
            $tar->addString(self::MANIFEST_ENTRY, json_encode([
                'format' => 'quire-backup',
                'version' => 1,
                'created_at' => new \DateTimeImmutable()->format(\DATE_ATOM),
                'files' => BackupFiles::None === $files ? 'none' : (null === $filesDirectory ? 'archive' : 'directory'),
                'selection' => $files->value,
                'objects' => $report->objects,
                'copied' => $report->copied,
                'reused' => $report->reused,
                'not_copied' => $report->notCopied,
                'missing' => $report->missing,
                'corrupt' => $report->corrupt,
            ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            $tar->finish();

            return $report;
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock_shared(?)', [GarbageCollector::BACKUP_LOCK]);
            @unlink($dump);
        }
    }

    /**
     * Dumps the database from a snapshot, and lists the stored files in the same snapshot, so the list is
     * exactly the dump's.
     *
     * @return list<array{disk: string, key: string, size: int, sha256: string}>
     */
    private function dumpDatabase(string $dump): array
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $this->connection->fetchOne('SELECT pg_export_snapshot()');
            $this->tools->run('pg_dump', [...$this->dumpArguments(), '--snapshot='.(\is_string($snapshot) ? $snapshot : ''), '--file='.$dump]);

            $objects = [];

            if (null !== $this->connection->fetchOne('SELECT to_regclass(?)', [DatabaseSchema::NAME.'.storage_object'])) {
                foreach ($this->connection->fetchAllAssociative('SELECT disk, key, size, sha256 FROM quire.storage_object ORDER BY disk, key') as $row) {
                    $row = new Row($row);
                    $objects[] = ['disk' => $row->string('disk'), 'key' => $row->string('key'), 'size' => $row->int('size'), 'sha256' => $row->string('sha256')];
                }
            }

            return $objects;
        } finally {
            $this->connection->commit();
        }
    }

    /**
     * @param list<array{disk: string, key: string, size: int, sha256: string}> $objects
     */
    private function copyFiles(array $objects, BackupFiles $selection, TarWriter $tar, ?string $filesDirectory): BackupReport
    {
        $copied = $reused = $notCopied = 0;
        $missing = $corrupt = [];

        foreach ($objects as $object) {
            $name = $object['disk'].':'.$object['key'];
            $disk = $this->disks->has($object['disk']) ? $this->disks->get($object['disk']) : null;

            if (!$disk instanceof Disk) {
                $missing[] = $name;

                continue;
            }

            if (BackupFiles::None === $selection || (BackupFiles::Local === $selection && 'local' !== $disk->adapter)) {
                ++$notCopied;

                continue;
            }

            $target = null === $filesDirectory ? null : $filesDirectory.'/'.$object['disk'].'/'.$object['key'];

            if (null !== $target && is_file($target) && filesize($target) === $object['size']) {
                ++$reused;

                continue;
            }

            if (!$disk->files->fileExists($object['key'])) {
                $missing[] = $name;

                continue;
            }

            if ($disk->files->fileSize($object['key']) !== $object['size']) {
                $corrupt[] = $name;

                continue;
            }

            $hash = hash_init('sha256');
            $stream = $disk->files->readStream($object['key']);

            try {
                if (null === $target) {
                    $tar->addStream(self::FILES_PREFIX.$object['disk'].'/'.$object['key'], $stream, $object['size'], inspect: static function (string $chunk) use ($hash): void {
                        hash_update($hash, $chunk);
                    });
                } else {
                    $this->copyToDirectory($stream, $target, $hash);
                }
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (hash_final($hash) !== $object['sha256']) {
                // In the archive, but a restore checks it and won't write it.
                $corrupt[] = $name;
                if (null !== $target) {
                    @unlink($target);
                }

                continue;
            }

            ++$copied;
        }

        return new BackupReport(\count($objects), $copied, $reused, $notCopied, $missing, $corrupt);
    }

    /**
     * @param resource $stream
     */
    private function copyToDirectory(mixed $stream, string $target, \HashContext $hash): void
    {
        if (!is_dir(\dirname($target)) && !@mkdir(\dirname($target), 0o700, true) && !is_dir(\dirname($target))) {
            throw new BackupException(\sprintf('Can\'t create the directory "%s".', \dirname($target)));
        }

        $partial = $target.'.partial';
        $out = $this->opened(fopen($partial, 'w'), \sprintf('Can\'t write to "%s".', $partial));

        try {
            while (!feof($stream)) {
                $chunk = (string) fread($stream, 1048576);
                hash_update($hash, $chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
        }

        rename($partial, $target);
    }

    /**
     * Writes each stored file to its disk, unless the disk has it already, after checking its checksum:
     * a damaged copy is never stored under the name of the content it should have.
     *
     * @param list<array{disk: string, key: string, size: int, sha256: string}> $objects
     * @param array<string, array{offset: int, size: int}>                      $entries
     *
     * @return array{restored: int, alreadyThere: int, corrupt: list<string>}
     */
    private function restoreFiles(StorageDisks $disks, array $objects, TarReader $archive, array $entries, ?string $filesDirectory): array
    {
        $restored = $alreadyThere = 0;
        $corrupt = [];

        foreach ($objects as $object) {
            $disk = $disks->get($object['disk']);
            $entry = $entries[self::FILES_PREFIX.$object['disk'].'/'.$object['key']] ?? null;
            $source = null === $filesDirectory ? null : $filesDirectory.'/'.$object['disk'].'/'.$object['key'];

            if (null === $entry && (null === $source || !is_file($source))) {
                continue;
            }

            if ($disk->files->fileExists($object['key']) && $disk->files->fileSize($object['key']) === $object['size']) {
                ++$alreadyThere;

                continue;
            }

            $copy = $this->opened(tmpfile(), 'Can\'t create a temporary file.');
            $hash = hash_init('sha256');

            try {
                $update = static function (string $chunk) use ($hash): void {
                    hash_update($hash, $chunk);
                };

                if (null !== $entry) {
                    $archive->copy($entry, $copy, $update);
                } else {
                    $in = $this->opened(fopen($source, 'r'), \sprintf('Can\'t read "%s".', $source));

                    while (!feof($in)) {
                        $chunk = (string) fread($in, 1048576);
                        $update($chunk);
                        fwrite($copy, $chunk);
                    }

                    fclose($in);
                }

                if (hash_final($hash) !== $object['sha256']) {
                    $corrupt[] = $object['disk'].':'.$object['key'];

                    continue;
                }

                rewind($copy);

                if ('local' === $disk->adapter) {
                    $partial = $object['key'].'.part-'.bin2hex(random_bytes(6));
                    $disk->files->writeStream($partial, $copy);
                    $disk->files->move($partial, $object['key']);
                } else {
                    $disk->files->writeStream($object['key'], $copy);
                }

                ++$restored;
            } finally {
                fclose($copy);
            }
        }

        return ['restored' => $restored, 'alreadyThere' => $alreadyThere, 'corrupt' => $corrupt];
    }

    /**
     * @param \Closure(string): void $output
     */
    private function restoreDatabase(string $dump, \Closure $output): void
    {
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
                $dump,
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
    }

    /**
     * @return list<string> "disk:key" of the stored files the database lists that aren't on their disks
     */
    private function missingFiles(StorageDisks $disks): array
    {
        if (null === $this->connection->fetchOne('SELECT to_regclass(?)', [DatabaseSchema::NAME.'.storage_object'])) {
            return [];
        }

        $missing = [];

        foreach ($this->connection->fetchAllAssociative('SELECT disk, key FROM quire.storage_object ORDER BY disk, key') as $row) {
            $row = new Row($row);
            [$disk, $key] = [$row->string('disk'), $row->string('key')];

            if (!$disks->has($disk) || !$disks->get($disk)->files->fileExists($key)) {
                $missing[] = $disk.':'.$key;
            }
        }

        return $missing;
    }

    /**
     * @return array{array<string, array{offset: int, size: int}>, array<mixed>} The entries and the manifest
     */
    private function openArchive(string $file): array
    {
        $reader = new TarReader($file);
        $entries = $reader->entries();
        $manifest = isset($entries[self::MANIFEST_ENTRY]) ? json_decode($reader->read($entries[self::MANIFEST_ENTRY]), true) : null;

        if (!isset($entries[self::DUMP_ENTRY]) || !\is_array($manifest) || 'quire-backup' !== ($manifest['format'] ?? null)) {
            throw new BackupException(\sprintf('"%s" is a tar archive, but not a backup made by quire:backup.', $file));
        }

        return [$entries, $manifest];
    }

    /**
     * @param array<string, array{offset: int, size: int}> $entries
     */
    private function extractDump(string $file, array $entries): string
    {
        $dump = $this->temporaryFile('quire-restore-');
        $handle = $this->opened(fopen($dump, 'w'), 'Can\'t create a temporary file for the database dump.');

        try {
            new TarReader($file)->copy($entries[self::DUMP_ENTRY], $handle);
        } finally {
            fclose($handle);
        }

        return $dump;
    }

    /**
     * @param string $dump The pg_dump archive
     * @param string $file The backup it's from, for messages
     */
    private function inspectDump(string $dump, string $file): BackupContents
    {
        try {
            $list = $this->tools->run('pg_restore', ['--list', $dump]);
        } catch (BackupException $e) {
            throw new BackupException(\sprintf('"%s" isn\'t a backup made by quire:backup or pg_dump --format=custom. %s', $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        $tables = $this->copyData($this->tools->run('pg_restore', ['--data-only', '--schema='.DatabaseSchema::NAME, '--table=migration_versions', '--table=extension', '--table=storage_object', '--file=-', $dump]));

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
        $objects = array_count_values(array_map(static fn (array $row): string => (string) $row['disk'], $tables[DatabaseSchema::NAME.'.storage_object'] ?? []));
        ksort($objects);

        return new BackupContents(
            createdAt: false === $createdAt ? null : $createdAt,
            database: $header('dbname'),
            serverVersion: $header('Dumped from database version'),
            tables: (int) preg_match_all('/^\d+; \d+ \d+ TABLE '.DatabaseSchema::NAME.' /m', $list),
            migrations: array_map(static fn (array $row): string => (string) $row['version'], $tables[MigrationStorage::TABLE]),
            enabledExtensions: $extensions,
            objects: $objects,
        );
    }

    /**
     * @return list<array{disk: string, key: string, size: int, sha256: string}>
     */
    private function objectsInDump(string $dump): array
    {
        $tables = $this->copyData($this->tools->run('pg_restore', ['--data-only', '--schema='.DatabaseSchema::NAME, '--table=storage_object', '--file=-', $dump]));

        return array_map(static fn (array $row): array => [
            'disk' => (string) $row['disk'],
            'key' => (string) $row['key'],
            'size' => (int) $row['size'],
            'sha256' => (string) $row['sha256'],
        ], $tables[DatabaseSchema::NAME.'.storage_object'] ?? []);
    }

    /**
     * @param resource|false $handle
     *
     * @return resource
     */
    private function opened(mixed $handle, string $error): mixed
    {
        return false === $handle ? throw new BackupException($error) : $handle;
    }

    private function temporaryFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if (false === $path || !chmod($path, 0o600)) {
            throw new BackupException('Can\'t create a temporary file.');
        }

        return $path;
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
