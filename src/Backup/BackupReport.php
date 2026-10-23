<?php

declare(strict_types=1);

namespace Quire\Backup;

/**
 * What a backup copied of the stored files.
 *
 * @api
 */
final readonly class BackupReport
{
    /**
     * @param int          $objects   Stored files the database lists
     * @param int          $copied    Copied into the backup (or into the files directory)
     * @param int          $reused    Already in the files directory from an earlier backup
     * @param int          $notCopied On disks the backup doesn't copy (see {@see BackupFiles})
     * @param list<string> $missing   "disk:key" of files the database lists but the disk doesn't have
     * @param list<string> $corrupt   "disk:key" of files whose content doesn't match their checksum
     */
    public function __construct(
        public int $objects,
        public int $copied,
        public int $reused,
        public int $notCopied,
        public array $missing,
        public array $corrupt,
    ) {
    }
}
