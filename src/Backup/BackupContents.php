<?php

declare(strict_types=1);

namespace Quire\Backup;

/**
 * What a backup file holds, read without restoring it.
 *
 * The migrations and the enabled extensions describe the code the backup needs: a backup is compatible
 * when the code has every migration that had run, for each part of Quire that's installed.
 *
 * @api
 */
final readonly class BackupContents
{
    /** The backup is an archive of the database and stored files, made by quire:backup */
    public const string ARCHIVE = 'archive';

    /** The backup is a pg_dump archive of the database only, as quire:backup made them before files */
    public const string DUMP = 'dump';

    /**
     * @param list<string>                      $migrations        Migrations that had run, e.g. "Quire\Migrations\Version20261021120000"
     * @param list<string>                      $enabledExtensions IDs of the extensions that were enabled, sorted
     * @param self::ARCHIVE|self::DUMP          $format
     * @param 'archive'|'directory'|'none'|null $files             Where the copies of stored files are: in the archive, in a
     *                                                             files directory, nowhere; null for a dump
     * @param array<string, int>                $objects           How many stored files the database lists, by disk
     * @param int                               $filesInArchive    Copies of stored files in the archive
     * @param list<string>                      $missingWhenMade   "disk:key" of files that were already missing, or corrupt, when the backup was made
     */
    public function __construct(
        public ?\DateTimeImmutable $createdAt,
        public string $database,
        public string $serverVersion,
        public int $tables,
        public array $migrations,
        public array $enabledExtensions,
        public string $format = self::DUMP,
        public ?string $files = null,
        public array $objects = [],
        public int $filesInArchive = 0,
        public array $missingWhenMade = [],
    ) {
    }
}
