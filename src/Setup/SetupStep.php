<?php

declare(strict_types=1);

namespace Quire\Setup;

/**
 * One step of setting a new site up, as the person looking may take it: what it is, whether it's done,
 * and where to do it. "Done" is read from the site as it is (a title set, a first post written), never
 * kept: an owner who deletes the first post sees the step again.
 *
 * @api
 */
final readonly class SetupStep
{
    public function __construct(
        public string $id,
        public string $title,
        public string $text,
        public bool $done,
        public ?string $url = null,
        public ?string $action = null,
    ) {
    }
}
