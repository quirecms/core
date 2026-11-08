<?php

declare(strict_types=1);

namespace Quire\Runtime;

/**
 * What a server that manages Quire sites (e.g. Quire Server) may rely on, by version: the
 * "quire:about --format=json" report, "quire:install --no-interaction" with "--invite", running
 * behind a proxy (compose.proxy.yaml), the labels of a site's Docker containers
 * ("com.quirecms.site", "com.quirecms.contract"), and, from version 2, quire:backup's exit codes and
 * manifest. A change a server must know about raises the version; additions that servers can ignore
 * don't.
 *
 * Version 2: quire:backup exits with 0 for a complete backup, 1 for none, and 3
 * ({@see \Quire\Backup\Console\BackupCommand::INCOMPLETE}) for one that was written but is incomplete,
 * where version 1 exited with 0. The backup's manifest.json says so too: "complete" (true or false), and
 * "absent", each file it has no good copy of with why ("unknown_disk", "missing", "damaged"). What the
 * commands print is for people: its wording isn't part of the contract.
 *
 * @api
 */
final class ServerContract
{
    public const int VERSION = 2;
}
