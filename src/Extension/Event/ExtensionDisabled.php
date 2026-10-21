<?php

declare(strict_types=1);

namespace Quire\Extension\Event;

use Quire\Extension\ExtensionManifest;

/**
 * Dispatched after an extension was disabled. When several are disabled together, events follow
 * reverse boot order (dependents first).
 *
 * @api
 */
final readonly class ExtensionDisabled
{
    /**
     * @param ExtensionManifest|null $extension Null when the extension is no longer installed
     */
    public function __construct(
        public string $id,
        public ?ExtensionManifest $extension,
    ) {
    }
}
