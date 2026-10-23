<?php

declare(strict_types=1);

namespace Quire\Content\Revision\Internal;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\DiffOnlyOutputBuilder;

/**
 * Line-level comparison of texts, for diffs and merges.
 *
 * @internal
 */
final class Lines
{
    public const int SAME = Differ::OLD;
    public const int ADDED = Differ::ADDED;
    public const int REMOVED = Differ::REMOVED;

    /**
     * @return list<string> The lines, each with its line ending (the last may have none)
     */
    public static function split(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $lines = preg_split('/(?<=\n)/', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $lines ? [$text] : $lines;
    }

    /**
     * A shortest edit script from one list of lines to another.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return list<array{string, self::SAME|self::ADDED|self::REMOVED}>
     */
    public static function diff(array $from, array $to): array
    {
        $script = [];

        foreach (new Differ(new DiffOnlyOutputBuilder())->diffToArray($from, $to) as [$line, $operation]) {
            // Skips the notes about line endings the differ adds.
            if (\is_string($line) && \in_array($operation, [self::SAME, self::ADDED, self::REMOVED], true)) {
                $script[] = [$line, $operation];
            }
        }

        return $script;
    }

    /**
     * For each line of $from that $to keeps, its index in $to.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return array<int, int>
     */
    public static function matches(array $from, array $to): array
    {
        $matches = [];
        [$i, $j] = [0, 0];

        foreach (self::diff($from, $to) as [, $operation]) {
            match ($operation) {
                self::SAME => $matches[$i++] = $j++,
                self::REMOVED => ++$i,
                self::ADDED => ++$j,
            };
        }

        return $matches;
    }
}
