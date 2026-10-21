<?php

declare(strict_types=1);

namespace Quire\Extension;

/**
 * What Quire knows about an installed extension or theme, read from its Composer package.
 *
 * @api
 */
final readonly class ExtensionManifest
{
    /**
     * @param string       $id          Stable identifier, e.g. "acme-reviews"
     * @param string       $packageName Composer package name, e.g. "acme/reviews"
     * @param string       $path        Absolute path to the installed package
     * @param list<string> $requires    IDs of extensions that must be enabled first
     * @param list<string> $optional    IDs of extensions that load first when enabled, but aren't required
     */
    public function __construct(
        public string $id,
        public string $packageName,
        public ExtensionType $type,
        public string $version,
        public string $path,
        public string $title,
        public ?string $description = null,
        public ?string $category = null,
        public ?string $icon = null,
        public array $requires = [],
        public array $optional = [],
    ) {
    }
}
