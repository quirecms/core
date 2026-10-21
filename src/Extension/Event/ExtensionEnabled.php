<?php

declare(strict_types=1);

namespace Quire\Extension\Event;

use Quire\Extension\ExtensionManifest;

/**
 * Dispatched after an extension was enabled. When several are enabled together, events follow boot order.
 *
 * @api
 */
final readonly class ExtensionEnabled
{
    public function __construct(
        public ExtensionManifest $extension,
    ) {
    }
}
