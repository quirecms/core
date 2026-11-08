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
     * @param int          $objects        Stored files the database lists
     * @param int          $copied         Copied into the backup (or into the files directory)
     * @param int          $reused         Already in the files directory from an earlier backup
     * @param int          $notCopied      On disks the backup doesn't copy (see {@see BackupFiles})
     * @param list<string> $missing        "disk:key" of files the database lists but the disk doesn't have
     * @param list<string> $corrupt        "disk:key" of files whose content doesn't match their checksum
     * @param list<string> $unknownDisks   Disks the database lists files on that neither the site nor any
     *                                     installed extension has, e.g. of an extension removed with Composer
     * @param list<string> $onUnknownDisks "disk:key" of the files on them
     */
    public function __construct(
        public int $objects,
        public int $copied,
        public int $reused,
        public int $notCopied,
        public array $missing,
        public array $corrupt,
        public array $unknownDisks = [],
        public array $onUnknownDisks = [],
    ) {
    }

    /**
     * Whether the backup has a good copy of every stored file it was to copy. Files not copied by
     * choice (see {@see BackupFiles}) don't count; files on unknown disks always do.
     */
    public function complete(): bool
    {
        return [] === $this->missing && [] === $this->corrupt && [] === $this->onUnknownDisks;
    }

    /**
     * @return list<string> "disk:key" of every file the backup has no good copy of
     */
    public function absent(): array
    {
        return [...$this->onUnknownDisks, ...$this->missing, ...$this->corrupt];
    }
}
