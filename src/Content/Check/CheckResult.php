<?php

declare(strict_types=1);

namespace Quire\Content\Check;

/**
 * A finding of a check, with the level the site gives that check.
 *
 * @api
 */
final readonly class CheckResult
{
    public function __construct(
        public string $check,
        public string $label,
        public Level $level,
        public Finding $finding,
    ) {
    }
}
