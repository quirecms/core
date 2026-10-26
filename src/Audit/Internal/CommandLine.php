<?php

declare(strict_types=1);

namespace Quire\Audit\Internal;

/**
 * Writes a console command as someone would type it in a POSIX shell.
 *
 * @internal
 */
final class CommandLine
{
    /**
     * @param list<string>                    $arguments
     * @param array<string, string|true|null> $options   Name => value; true for flags, null to leave it out
     */
    public static function of(string $command, array $arguments = [], array $options = []): string
    {
        $words = ['bin/console', $command];

        foreach ($arguments as $argument) {
            $words[] = self::quote($argument);
        }

        foreach ($options as $name => $value) {
            if (true === $value) {
                $words[] = '--'.$name;
            } elseif (null !== $value) {
                $words[] = '--'.$name.'='.self::quote($value);
            }
        }

        return implode(' ', $words);
    }

    public static function quote(string $word): string
    {
        // Arguments starting with "-" would be read as options.
        if (1 === preg_match('~^[A-Za-z0-9_.,:/@%+=][A-Za-z0-9_.,:/@%+=-]*$~', $word)) {
            return $word;
        }

        return "'".str_replace("'", "'\\''", $word)."'";
    }
}
