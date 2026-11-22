<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * What became of an installed language artifact: loaded, with its files, or ignored, with why. An artifact
 * that doesn't fit never stops the site (an update of the package it translates may come before its next
 * artifact), but it's said: in quire:about, when the site is built, and to Quire Server's update plan.
 *
 * @api
 */
final readonly class ArtifactStatus
{
    /**
     * @param list<string> $files Its files, when loaded
     */
    public function __construct(
        public string $name,
        public ?string $package,
        public ?string $locale,
        public bool $loaded,
        public ?string $reason = null,
        public array $files = [],
    ) {
    }

    /**
     * @return array{name: string, package: ?string, locale: ?string, status: 'loaded'|'ignored', reason: ?string, files: list<string>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'package' => $this->package, 'locale' => $this->locale, 'status' => $this->loaded ? 'loaded' : 'ignored', 'reason' => $this->reason, 'files' => $this->files];
    }
}
