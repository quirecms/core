<?php

declare(strict_types=1);

namespace Quire\Backup;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;

/**
 * The backups Quire made, e.g. with quire:backup: when, how big, and whether they worked. For telling
 * people when the site was last backed up. Backups made otherwise, e.g. by the database's own tools,
 * aren't in it; a restore brings back the log as it was in the backup.
 *
 * @api
 */
final readonly class BackupLog
{
    /** Runs kept: the log answers "when", it isn't an archive */
    private const int KEEP = 100;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(BackupRun $run): void
    {
        $this->connection->insert('quire.backup_run', [
            'finished_at' => $run->finishedAt->format(\DATE_ATOM),
            'ok' => $run->ok ? 'true' : 'false',
            'bytes' => $run->bytes,
            'target' => $run->target,
            'problem' => $run->problem,
        ]);
        $this->connection->executeStatement('DELETE FROM quire.backup_run WHERE id NOT IN (SELECT id FROM quire.backup_run ORDER BY finished_at DESC, id DESC LIMIT ?)', [self::KEEP]);
    }

    /**
     * The latest backup, whether it worked or not.
     */
    public function last(): ?BackupRun
    {
        return $this->find('');
    }

    public function lastSuccessful(): ?BackupRun
    {
        return $this->find('WHERE ok');
    }

    private function find(string $where): ?BackupRun
    {
        $columns = $this->connection->fetchAssociative(\sprintf('SELECT finished_at, ok, bytes, target, problem FROM quire.backup_run %s ORDER BY finished_at DESC, id DESC LIMIT 1', $where));

        if (false === $columns) {
            return null;
        }

        $row = new Row($columns);

        return new BackupRun($row->date('finished_at'), true === $columns['ok'], \is_int($columns['bytes']) ? $columns['bytes'] : null, $row->nullableString('target'), $row->nullableString('problem'));
    }
}
