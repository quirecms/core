<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Quire\Content\Revision\Internal\Lines;
use Quire\Content\Revision\Internal\Values;

/**
 * What changed between two versions of an item's text and fields, e.g. between two revisions, or
 * what a pending revision changes. Because bodies are text (Markdown), the body diff is exact and
 * readable line by line.
 *
 *     $diff = Diff::between(Snapshot::of($older), Snapshot::of($newer));
 *     $diff->changes['title'] ?? null;   // ['old' => 'Opening hours', 'new' => 'Opening times']
 *     echo $diff->unified();             // the body, as a unified diff
 *
 * @api
 */
final readonly class Diff
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes   Changed properties ("title", "excerpt", "body", "body_format",
     *                                                                "fields.<name>"); a field that's absent on one side is null there
     * @param list<DiffLine>                               $bodyLines Every line of the body, when it changed; otherwise none
     */
    public function __construct(
        public array $changes,
        public array $bodyLines,
    ) {
    }

    public static function between(Snapshot $old, Snapshot $new): self
    {
        $changes = [];
        $before = $old->toArray();
        $after = $new->toArray();

        foreach (['title', 'excerpt', 'body', 'body_format'] as $property) {
            if (!Values::same($before[$property], $after[$property])) {
                $changes[$property] = ['old' => $before[$property], 'new' => $after[$property]];
            }
        }

        foreach (array_keys($old->fields + $new->fields) as $name) {
            if (!\array_key_exists($name, $old->fields) || !\array_key_exists($name, $new->fields) || !Values::same($old->fields[$name], $new->fields[$name])) {
                $changes['fields.'.$name] = ['old' => $old->fields[$name] ?? null, 'new' => $new->fields[$name] ?? null];
            }
        }

        return new self($changes, isset($changes['body']) ? self::lines($old->body, $new->body) : []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->changes;
    }

    /**
     * The body's changes as a unified diff, as `diff -u` and Git show them, or "" when it didn't change.
     *
     * @param int $context Unchanged lines shown around each change
     */
    public function unified(int $context = 3): string
    {
        $lines = $this->bodyLines;
        $changed = array_keys(array_filter($lines, static fn (DiffLine $line): bool => DiffLine::SAME !== $line->operation));

        if ([] === $changed) {
            return '';
        }

        // Groups changes whose context overlaps into hunks.
        $hunks = [];

        foreach ($changed as $index) {
            $from = max(0, $index - $context);
            $to = min(\count($lines) - 1, $index + $context);
            $last = array_key_last($hunks);

            if (null !== $last && $from <= $hunks[$last][1] + 1) {
                $hunks[$last][1] = $to;
            } else {
                $hunks[] = [$from, $to];
            }
        }

        $output = "--- old\n+++ new\n";

        foreach ($hunks as [$from, $to]) {
            $hunk = \array_slice($lines, $from, $to - $from + 1);
            $old = array_values(array_filter($hunk, static fn (DiffLine $line): bool => null !== $line->oldNumber));
            $new = array_values(array_filter($hunk, static fn (DiffLine $line): bool => null !== $line->newNumber));
            $output .= \sprintf("@@ -%s +%s @@\n", $this->range($old, 'oldNumber', $lines, $from), $this->range($new, 'newNumber', $lines, $from));

            foreach ($hunk as $line) {
                $output .= match ($line->operation) {
                    DiffLine::ADDED => '+',
                    DiffLine::REMOVED => '-',
                    default => ' ',
                }.$line->text."\n";
            }
        }

        return $output;
    }

    /**
     * @return list<DiffLine>
     */
    private static function lines(string $old, string $new): array
    {
        $lines = [];
        [$oldNumber, $newNumber] = [0, 0];

        foreach (Lines::diff(Lines::split($old), Lines::split($new)) as [$text, $operation]) {
            $text = rtrim($text, "\r\n");
            $lines[] = match ($operation) {
                Lines::SAME => new DiffLine(DiffLine::SAME, $text, ++$oldNumber, ++$newNumber),
                Lines::REMOVED => new DiffLine(DiffLine::REMOVED, $text, ++$oldNumber, null),
                Lines::ADDED => new DiffLine(DiffLine::ADDED, $text, null, ++$newNumber),
            };
        }

        return $lines;
    }

    /**
     * A hunk's line range in one text, e.g. "12,7" (or "11,0" when it has no lines there).
     *
     * @param list<DiffLine>          $side   The hunk's lines in that text
     * @param 'oldNumber'|'newNumber' $number
     * @param list<DiffLine>          $all
     */
    private function range(array $side, string $number, array $all, int $from): string
    {
        if ([] !== $side) {
            return \sprintf('%d,%d', $side[0]->{$number}, \count($side));
        }

        // An empty side starts after the last line before the hunk.
        $before = 0;

        foreach (\array_slice($all, 0, $from) as $line) {
            $before = $line->{$number} ?? $before;
        }

        return \sprintf('%d,0', $before);
    }
}
