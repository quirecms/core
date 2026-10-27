<?php

declare(strict_types=1);

namespace Quire\Runtime;

/**
 * What a server that manages Quire sites (e.g. Quire Server) may rely on, by version: the
 * "quire:about --format=json" report, "quire:install --no-interaction" with "--invite", running
 * behind a proxy (compose.proxy.yaml), and the labels of a site's Docker containers
 * ("com.quirecms.site", "com.quirecms.contract"). A change a server must know about raises the
 * version; additions that servers can ignore don't.
 *
 * @api
 */
final class ServerContract
{
    public const int VERSION = 1;
}
