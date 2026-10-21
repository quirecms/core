<?php

declare(strict_types=1);

namespace Quire\Settings\Console;

/**
 * Writes setting values the way they're typed on the command line.
 *
 * @internal
 */
final class SettingValueFormatter
{
    public static function format(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        };
    }
}
