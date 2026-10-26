<?php

declare(strict_types=1);

namespace Quire\Tests\Action;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Action\CommandLine;

final class CommandLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function words(): iterable
    {
        yield 'plain' => ['site.title', 'site.title'];
        yield 'an email' => ['ada@example.com', 'ada@example.com'];
        yield 'a time with an offset' => ['2026-12-24T09:00:00+01:00', '2026-12-24T09:00:00+01:00'];
        yield 'spaces' => ['My site', "'My site'"];
        yield 'an apostrophe' => ["It's", "'It'\\''s'"];
        yield 'shell characters' => ['$(rm -rf /); `x` *', "'$(rm -rf /); `x` *'"];
        yield 'JSON' => ['{"a":1}', '\'{"a":1}\''];
        yield 'empty' => ['', "''"];
        yield 'a leading dash' => ['-x', "'-x'"];
    }

    #[DataProvider('words')]
    public function testWordsAreQuotedOnlyWhenTheyNeedIt(string $word, string $quoted): void
    {
        self::assertSame($quoted, CommandLine::quote($word));
    }

    public function testArgumentsThenOptions(): void
    {
        self::assertSame(
            "bin/console quire:user:delete ada --reassign='Grace Hopper' --keep-content",
            CommandLine::of('quire:user:delete', ['ada'], ['reassign' => 'Grace Hopper', 'skipped' => null, 'keep-content' => true]),
        );
    }
}
