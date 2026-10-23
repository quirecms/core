<?php

declare(strict_types=1);

namespace Quire\Tests\Content\Revision;

use PHPUnit\Framework\TestCase;
use Quire\Content\Revision\Diff;
use Quire\Content\Revision\DiffLine;
use Quire\Content\Revision\Snapshot;

final class DiffTest extends TestCase
{
    public function testListsWhatChanged(): void
    {
        $diff = Diff::between(
            new Snapshot('Hours', null, "a\n", 'markdown', ['mood' => 'happy', 'tags' => ['x'], 'extra' => ['a' => 1, 'b' => 2]]),
            new Snapshot('Opening hours', 'Short', "a\n", 'markdown', ['mood' => 'happy', 'rating' => 4, 'extra' => ['b' => 2, 'a' => 1]]),
        );

        self::assertSame([
            'title' => ['old' => 'Hours', 'new' => 'Opening hours'],
            'excerpt' => ['old' => null, 'new' => 'Short'],
            'fields.mood' => null,
            'fields.tags' => ['old' => ['x'], 'new' => null],
            'fields.extra' => null,
            'fields.rating' => ['old' => null, 'new' => 4],
        ], array_map(static fn (string $property): ?array => $diff->changes[$property] ?? null, ['title' => 'title', 'excerpt' => 'excerpt', 'fields.mood' => 'fields.mood', 'fields.tags' => 'fields.tags', 'fields.extra' => 'fields.extra', 'fields.rating' => 'fields.rating']));
        self::assertSame([], $diff->bodyLines, 'The body didn\'t change.');
        self::assertSame('', $diff->unified());
        self::assertTrue(Diff::between(new Snapshot('T', null, '', 'text', []), new Snapshot('T', null, '', 'text', []))->isEmpty());
    }

    public function testDiffsTheBodyByLine(): void
    {
        $old = implode('', array_map(static fn (int $n): string => "line $n\n", range(1, 12)));
        $new = str_replace(["line 2\n", "line 11\n"], ["line two\n", ''], $old)."line 13\n";

        $diff = Diff::between(new Snapshot('T', null, $old, 'markdown', []), new Snapshot('T', null, $new, 'markdown', []));

        self::assertEquals(new DiffLine(DiffLine::REMOVED, 'line 2', 2, null), $diff->bodyLines[1]);
        self::assertEquals(new DiffLine(DiffLine::ADDED, 'line two', null, 2), $diff->bodyLines[2]);
        self::assertEquals(new DiffLine(DiffLine::SAME, 'line 3', 3, 3), $diff->bodyLines[3]);
        self::assertSame(<<<'DIFF'
            --- old
            +++ new
            @@ -1,5 +1,5 @@
             line 1
            -line 2
            +line two
             line 3
             line 4
             line 5
            @@ -8,5 +8,5 @@
             line 8
             line 9
             line 10
            -line 11
             line 12
            +line 13

            DIFF, $diff->unified());
        self::assertStringStartsWith("--- old\n+++ new\n@@ -1,12 +1,12 @@\n", $diff->unified(context: 10), 'Overlapping context makes one hunk.');
    }

    public function testDiffsAdditionsToAnEmptyBody(): void
    {
        $diff = Diff::between(new Snapshot('T', null, '', 'markdown', []), new Snapshot('T', null, "new\n", 'markdown', []));

        self::assertSame("--- old\n+++ new\n@@ -0,0 +1,1 @@\n+new\n", $diff->unified());
    }
}
