<?php

declare(strict_types=1);

namespace Quire\Backup;

/**
 * Which stored files a backup copies. The database always lists every one, so a restore can check that
 * they're all there afterwards.
 *
 * @api
 */
enum BackupFiles: string
{
    /** Files on local disks: the ones nothing else keeps a copy of. S3 buckets have their own versioning. */
    case Local = 'local';

    /** Files on every disk, S3 too, e.g. for a copy kept away from the provider, or to move to another */
    case All = 'all';

    /** None: the database only */
    case None = 'none';
}
