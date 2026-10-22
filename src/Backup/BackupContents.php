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
    /**
     * @param list<string> $migrations        Migrations that had run, e.g. "Quire\Migrations\Version20261021120000"
     * @param list<string> $enabledExtensions IDs of the extensions that were enabled, sorted
     */
    public function __construct(
        public ?\DateTimeImmutable $createdAt,
        public string $database,
        public string $serverVersion,
        public int $tables,
        public array $migrations,
        public array $enabledExtensions,
    ) {
    }
}
