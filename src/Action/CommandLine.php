<?php

declare(strict_types=1);

namespace Quire\Action;

/**
 * Writes a console command as someone would type it in a POSIX shell, e.g. for an action's
 * {@see ActionInterface::commandLine()}.
 *
 *     CommandLine::of('quire:content:transition', [$id, 'publish'], ['at' => '2026-12-24T09:00:00+01:00'])
 *     // bin/console quire:content:transition 0192… publish --at=2026-12-24T09:00:00+01:00
 *
 * @api
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

    /**
     * A repeatable option, e.g. " --role=editor --role=translator", to append to {@see of()}.
     *
     * @param list<string> $values
     */
    public static function repeated(string $name, array $values): string
    {
        return implode('', array_map(static fn (string $value): string => ' --'.$name.'='.self::quote($value), $values));
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
