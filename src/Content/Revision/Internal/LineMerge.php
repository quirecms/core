<?php

declare(strict_types=1);

namespace Quire\Content\Revision\Internal;

/**
 * Three-way merge of texts by line (diff3): two edits of the same original combine when they touch
 * different lines, and conflict when they change the same ones differently.
 *
 * @internal
 */
final class LineMerge
{
    /**
     * @return string|null The merged text, or null when the edits conflict
     */
    public static function merge(string $base, string $ours, string $theirs): ?string
    {
        if ($ours === $theirs || $theirs === $base) {
            return $ours;
        }

        if ($ours === $base) {
            return $theirs;
        }

        // Compared with a final line ending everywhere, so text added at the end doesn't conflict with
        // edits elsewhere only because the last line gained one.
        [$baseLines, $baseOpen] = self::lines($base);
        [$ourLines, $oursOpen] = self::lines($ours);
        [$theirLines, $theirsOpen] = self::lines($theirs);

        $ourMatches = Lines::matches($baseLines, $ourLines);
        $theirMatches = Lines::matches($baseLines, $theirLines);
        $merged = [];
        [$o, $a, $b] = [0, 0, 0];
        $baseCount = \count($baseLines);

        while ($o < $baseCount || $a < \count($ourLines) || $b < \count($theirLines)) {
            // A line both sides kept where it was: take it.
            if ($o < $baseCount && ($ourMatches[$o] ?? null) === $a && ($theirMatches[$o] ?? null) === $b) {
                $merged[] = $baseLines[$o];
                ++$o;
                ++$a;
                ++$b;

                continue;
            }

            // Otherwise the next line both sides kept ends a changed region.
            $next = $o;

            while ($next < $baseCount && (!isset($ourMatches[$next]) || !isset($theirMatches[$next]))) {
                ++$next;
            }

            $endA = $next < $baseCount ? $ourMatches[$next] : \count($ourLines);
            $endB = $next < $baseCount ? $theirMatches[$next] : \count($theirLines);
            $original = \array_slice($baseLines, $o, $next - $o);
            $ourChunk = \array_slice($ourLines, $a, $endA - $a);
            $theirChunk = \array_slice($theirLines, $b, $endB - $b);

            $chunk = match (true) {
                $ourChunk === $theirChunk, $theirChunk === $original => $ourChunk,
                $ourChunk === $original => $theirChunk,
                default => null,
            };

            if (null === $chunk) {
                return null;
            }

            array_push($merged, ...$chunk);
            [$o, $a, $b] = [$next, $endA, $endB];
        }

        $text = implode('', $merged);
        // Without a final line ending if the side that changed that left it out.
        $open = $oursOpen === $baseOpen ? $theirsOpen : $oursOpen;

        return $open && str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;
    }

    /**
     * @return array{list<string>, bool} The lines, each ending in a line break, and whether the text had none at the end
     */
    private static function lines(string $text): array
    {
        $open = '' !== $text && !str_ends_with($text, "\n");

        return [Lines::split($open ? $text."\n" : $text), $open];
    }
}
