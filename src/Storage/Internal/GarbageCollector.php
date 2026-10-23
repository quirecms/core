<?php

declare(strict_types=1);

namespace Quire\Storage\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use League\Flysystem\FileAttributes;
use Quire\Content\Internal\Row;
use Quire\Storage\ObjectStore;
use Quire\Storage\StorageDisks;

/**
 * Deletes stored objects that nothing references any more, and files that no object records.
 *
 * What references an object is read from PostgreSQL's catalog: every foreign key to
 * quire.storage_object(id), in core's tables and every extension's. So an extension's table counts as
 * soon as it has such a key, with nothing to register. Both kinds of garbage must be older than the
 * grace period, which covers uploads whose transaction is still open.
 *
 * @internal
 */
final readonly class GarbageCollector
{
    /**
     * Held exclusively while collecting, and shared by backups: a backup's files can't be collected
     * between its database dump and its copies of them.
     */
    public const int BACKUP_LOCK = 0x7175697265676301;

    /** Files that {@see ObjectStore} writes: "ab/cd/<sha256>.<ext>", and partial writes next to them */
    private const string OBJECT_FILE = '#^[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}\.[a-z0-9]+(\.part-[0-9a-f]{12})?$#';

    public function __construct(
        private Connection $connection,
        private StorageDisks $disks,
    ) {
    }

    /**
     * @param \Closure(string): void|null $log Told about each deletion
     *
     * @return array{objects: int, files: int} How many were (or, dry, would be) deleted
     */
    public function collect(\DateTimeImmutable $before, bool $dryRun = false, ?\Closure $log = null): array
    {
        $log ??= static function (string $message): void {};
        $this->connection->executeStatement('SELECT pg_advisory_lock(?)', [self::BACKUP_LOCK]);

        try {
            return [
                'objects' => $this->unreferencedObjects($before, $dryRun, $log),
                'files' => $this->unrecordedFiles($before, $dryRun, $log),
            ];
        } finally {
            $this->connection->executeStatement('SELECT pg_advisory_unlock(?)', [self::BACKUP_LOCK]);
        }
    }

    /**
     * @param \Closure(string): void $log
     */
    private function unreferencedObjects(\DateTimeImmutable $before, bool $dryRun, \Closure $log): int
    {
        $unreferenced = $this->unreferencedCondition();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.disk, o.key FROM quire.storage_object o WHERE o.stored_at < ? AND '.$unreferenced.' ORDER BY o.stored_at',
            [$before->format(\DATE_ATOM)],
        );
        $deleted = 0;

        foreach ($rows as $row) {
            $row = new Row($row);
            [$id, $disk, $key] = [$row->string('id'), $row->string('disk'), $row->string('key')];

            if ($dryRun) {
                $log(\sprintf('Would delete %s:%s', $disk, $key));
                ++$deleted;

                continue;
            }

            // Under the object's lock, so a store() of the same content waits; and checked again, as
            // something may reference it by now (the foreign key would refuse the delete anyway).
            $lock = ObjectStore::lockKey($disk, $key);
            $this->connection->executeStatement('SELECT pg_advisory_lock(?)', [$lock]);

            try {
                $gone = $this->connection->executeStatement(
                    'DELETE FROM quire.storage_object o WHERE o.id = ? AND o.stored_at < ? AND '.$unreferenced,
                    [$id, $before->format(\DATE_ATOM)],
                );

                if (1 === $gone && $this->disks->has($disk)) {
                    $this->disks->get($disk)->files->delete($key);
                }
            } catch (DriverException $e) {
                // Referenced after all: 23001 for ON DELETE RESTRICT, 23503 for NO ACTION.
                if (!\in_array($e->getSQLState(), ['23001', '23503'], true)) {
                    throw $e;
                }

                $gone = 0;
            } finally {
                $this->connection->executeStatement('SELECT pg_advisory_unlock(?)', [$lock]);
            }

            if (1 === $gone) {
                $log(\sprintf('Deleted %s:%s', $disk, $key));
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @param \Closure(string): void $log
     */
    private function unrecordedFiles(\DateTimeImmutable $before, bool $dryRun, \Closure $log): int
    {
        $deleted = 0;

        foreach ($this->disks->names() as $name) {
            $files = $this->disks->get($name)->files;

            foreach ($files->listContents('', true) as $item) {
                if (!$item instanceof FileAttributes || 1 !== preg_match(self::OBJECT_FILE, $item->path()) || ($item->lastModified() ?? \PHP_INT_MAX) >= $before->getTimestamp()) {
                    continue;
                }

                $key = (string) preg_replace('/\.part-[0-9a-f]{12}$/', '', $item->path());

                if ($key === $item->path() && false !== $this->connection->fetchOne('SELECT 1 FROM quire.storage_object WHERE disk = ? AND key = ?', [$name, $key])) {
                    continue;
                }

                if ($dryRun) {
                    $log(\sprintf('Would delete the unrecorded file %s:%s', $name, $item->path()));
                    ++$deleted;

                    continue;
                }

                $lock = ObjectStore::lockKey($name, $key);
                $this->connection->executeStatement('SELECT pg_advisory_lock(?)', [$lock]);

                try {
                    // A partial write is never recorded; a whole file may have been recorded meanwhile.
                    if ($key !== $item->path() || false === $this->connection->fetchOne('SELECT 1 FROM quire.storage_object WHERE disk = ? AND key = ?', [$name, $key])) {
                        $files->delete($item->path());
                        $log(\sprintf('Deleted the unrecorded file %s:%s', $name, $item->path()));
                        ++$deleted;
                    }
                } finally {
                    $this->connection->executeStatement('SELECT pg_advisory_unlock(?)', [$lock]);
                }
            }
        }

        return $deleted;
    }

    /**
     * "Nothing references o.id": a NOT EXISTS for each foreign key to quire.storage_object(id).
     */
    private function unreferencedCondition(): string
    {
        $references = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT quote_ident(n.nspname) || '.' || quote_ident(t.relname) AS "table", quote_ident(a.attname) AS "column"
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.confrelid = 'quire.storage_object'::regclass AND cardinality(c.conkey) = 1
            ORDER BY 1, 2
            SQL);

        $conditions = array_map(
            static fn (array $reference): string => \sprintf('NOT EXISTS (SELECT 1 FROM %s r WHERE r.%s = o.id)', new Row($reference)->string('table'), new Row($reference)->string('column')),
            $references,
        );

        return [] === $conditions ? 'TRUE' : implode(' AND ', $conditions);
    }
}
