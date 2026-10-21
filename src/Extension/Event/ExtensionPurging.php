<?php

declare(strict_types=1);

namespace Quire\Extension\Event;

use Quire\Extension\ExtensionManifest;

/**
 * Dispatched before an extension's data is permanently deleted (`quire:extension:disable --purge`).
 *
 * Listen to it to delete data that migrations don't cover, such as uploaded files. Afterwards Quire
 * rolls back the extension's migrations and deletes its settings.
 *
 * @api
 */
final readonly class ExtensionPurging
{
    public function __construct(
        public ExtensionManifest $extension,
    ) {
    }
}
