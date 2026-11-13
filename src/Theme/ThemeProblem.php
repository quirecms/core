<?php

declare(strict_types=1);

namespace Quire\Theme;

/**
 * What a theme check found: a warning, or an error.
 *
 * @api
 */
final readonly class ThemeProblem
{
    private function __construct(
        public string $message,
        public bool $error,
    ) {
    }

    public static function warning(string $message): self
    {
        return new self($message, false);
    }

    public static function error(string $message): self
    {
        return new self($message, true);
    }
}
