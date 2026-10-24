<?php

declare(strict_types=1);

namespace Quire\Tests\Content\Revision;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Content\Revision\Internal\LineMerge;
use Quire\Content\Revision\Merge;
use Quire\Content\Revision\Snapshot;

final class MergeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function texts(): iterable
    {
        $base = "# Opening hours\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n";

        yield 'only ours changed' => [$base, "# Opening times\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n", $base, "# Opening times\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n"];
        yield 'only theirs changed' => [$base, $base, "# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays.\n", "# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays.\n"];
        yield 'different lines' => [
            $base,
            "# Opening times\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n",
            "# Opening hours\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays and holidays.\n",
            "# Opening times\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays and holidays.\n",
        ];
        yield 'the same change on both sides' => [$base, str_replace('5', '6', $base), str_replace('5', '6', $base), str_replace('5', '6', $base)];
        yield 'the same line, changed differently' => [$base, str_replace('5', '6', $base), str_replace('5', '7', $base), null];
        yield 'lines added at the same place' => ["a\nb\n", "a\nx\nb\n", "a\ny\nb\n", null];
        yield 'lines added at different places' => ["a\nb\nc\n", "x\na\nb\nc\n", "a\nb\nc\ny\n", "x\na\nb\nc\ny\n"];
        yield 'a line removed, another changed' => ["a\nb\nc\nd\n", "a\nc\nd\n", "a\nb\nc\nD\n", "a\nc\nD\n"];
        yield 'both removed the same line' => ["a\nb\nc\n", "a\nc\n", "a\nc\n", "a\nc\n"];
        yield 'added at the end of a text without a final line break' => ["a\nb", "A\nb", "a\nb\nc", "A\nb\nc"];
        yield 'a final line break added on one side' => ["a\nb", "a\nb\n", "A\nb", "A\nb\n"];
        yield 'Windows line breaks' => ["a\r\nb\r\nc\r\n", "A\r\nb\r\nc\r\n", "a\r\nb\r\nC\r\n", "A\r\nb\r\nC\r\n"];
        yield 'from empty' => ['', 'ours', '', 'ours'];
        yield 'both from empty, differently' => ['', "ours\n", "theirs\n", null];
    }

    #[DataProvider('texts')]
    public function testMergesTextByLine(string $base, string $ours, string $theirs, ?string $expected): void
    {
        self::assertSame($expected, LineMerge::merge($base, $ours, $theirs));
        self::assertSame($expected, LineMerge::merge($base, $theirs, $ours), 'The order of the sides doesn\'t matter.');
    }

    public function testMergesEachFieldOnItsOwn(): void
    {
        $base = new Snapshot('Hours', null, "a\nb\n", 'markdown', ['mood' => 'happy', 'rating' => 3, 'tags' => ['x']]);
        $live = new Snapshot('Opening hours', null, "a\nb\n", 'markdown', ['mood' => 'sad', 'rating' => 3, 'tags' => ['x']]);
        $proposed = new Snapshot('Hours', 'Short', "a\nB\n", 'markdown', ['mood' => 'happy', 'rating' => 4, 'size' => 'xl']);

        $merge = Merge::of($base, $live, $proposed);

        self::assertTrue($merge->isClean());
        self::assertSame(['title' => 'Opening hours', 'excerpt' => 'Short', 'body' => "a\nB\n", 'body_format' => 'markdown', 'fields' => ['mood' => 'sad', 'rating' => 4, 'size' => 'xl']], $merge->result->toArray(), 'Removing "tags" is a change too.');
    }

    public function testReportsConflictsAndPrefersTheProposalInTheResult(): void
    {
        $base = new Snapshot('Hours', null, "a\n", 'markdown', ['mood' => 'happy', 'tags' => ['x']]);
        $live = new Snapshot('Opening hours', null, "b\n", 'markdown', ['mood' => 'sad', 'tags' => ['x', 'y']]);
        $proposed = new Snapshot('Our hours', null, "c\n", 'markdown', ['mood' => 'angry', 'tags' => ['x', 'y']]);

        $merge = Merge::of($base, $live, $proposed);

        self::assertFalse($merge->isClean());
        self::assertSame(['title', 'body', 'fields.mood'], array_keys($merge->conflicts), 'The same change on both sides isn\'t a conflict.');
        self::assertSame(['Our hours', "c\n", 'angry'], [$merge->result->title, $merge->result->body, $merge->result->fields['mood']]);
    }

    public function testTheResultTakesTheProposalOnlyInTheLinesThatConflict(): void
    {
        $base = "# Opening hours\n\nMonday to Friday, 9 to 5.\n\nClosed on Sundays.\n";
        $live = "# Opening hours\n\nMonday to Friday, 10 to 5.\n\nClosed on Sundays and holidays.\n";
        $proposed = "# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays.\n";

        self::assertNull(LineMerge::merge($base, $live, $proposed));
        self::assertSame("# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays and holidays.\n", LineMerge::merge($base, $live, $proposed, theirsOnConflict: true));

        $merge = Merge::of(new Snapshot('Hours', null, $base, 'markdown', []), new Snapshot('Hours', null, $live, 'markdown', []), new Snapshot('Hours', null, $proposed, 'markdown', []));
        self::assertSame(['body'], array_keys($merge->conflicts));
        self::assertSame("# Opening hours\n\nMonday to Friday, 9 to 6.\n\nClosed on Sundays and holidays.\n", $merge->result->body, 'Resolving it in favour of the proposal keeps the live edits elsewhere.');
    }

    public function testFieldsCompareLikeJsonObjects(): void
    {
        $base = new Snapshot('T', null, '', 'markdown', ['extra' => ['a' => 1, 'b' => 2]]);
        $live = new Snapshot('T', null, '', 'markdown', ['extra' => ['b' => 2, 'a' => 1]]);
        $proposed = new Snapshot('T', null, '', 'markdown', ['extra' => ['a' => 1, 'b' => 3]]);

        $merge = Merge::of($base, $live, $proposed);

        self::assertTrue($merge->isClean(), 'Key order isn\'t a change.');
        self::assertSame(['a' => 1, 'b' => 3], $merge->result->fields['extra']);
    }
}
