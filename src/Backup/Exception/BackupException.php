<?php

declare(strict_types=1);

namespace Quire\Backup\Exception;

/**
 * A backup couldn't be made, read or restored. The message says why and what to do.
 *
 * @api
 */
final class BackupException extends \RuntimeException
{
}
