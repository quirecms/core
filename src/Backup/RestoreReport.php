<?php

declare(strict_types=1);

namespace Quire\Backup;

/**
 * What a restore did with the stored files.
 *
 * @api
 */
final readonly class RestoreReport
{
    /**
     * @param int          $restored     Files written to their disks
     * @param int          $alreadyThere Files their disks already had
     * @param list<string> $corrupt      "disk:key" of files in the backup that don't match their checksum, and weren't written
     * @param list<string> $missing      "disk:key" of files the restored database lists that aren't on their disks
     */
    public function __construct(
        public BackupContents $backup,
        public int $restored,
        public int $alreadyThere,
        public array $corrupt,
        public array $missing,
    ) {
    }
}
