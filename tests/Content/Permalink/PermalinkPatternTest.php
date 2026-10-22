<?php

declare(strict_types=1);

namespace Quire\Tests\Content\Permalink;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Content\Permalink\PermalinkPattern;

final class PermalinkPatternTest extends TestCase
{
    public function testMatchesAndGeneratesUrls(): void
    {
        $pattern = PermalinkPattern::parse('/blog/{year}/{month}/{slug}', hierarchical: false);

        self::assertSame(['year' => '2026', 'month' => '10', 'slug' => 'hello-world'], $pattern->match('/blog/2026/10/hello-world'));
        self::assertNull($pattern->match('/blog/2026/hello-world'));
        self::assertNull($pattern->match('/blog/26/10/hello-world'), 'Years have four digits.');
        self::assertNull($pattern->match('/blog/2026/10/Hello'), 'Slugs are lowercase.');
        self::assertSame('/blog/2026/10/hello', $pattern->generate(['year' => '2026', 'month' => '10', 'slug' => 'hello']));
    }

    public function testPathsSpanSegments(): void
    {
        $pattern = PermalinkPattern::parse('/{path}', hierarchical: true);

        self::assertSame(['path' => 'about/team'], $pattern->match('/about/team'));
        self::assertSame(['path' => 'about'], $pattern->match('/about'));
        self::assertNull($pattern->match('/about/'), 'No empty segments.');
    }

    public function testStaticTextAroundPlaceholders(): void
    {
        $pattern = PermalinkPattern::parse('/p/{id}.html', hierarchical: false);

        self::assertSame(['id' => '0199f000-0000-7000-8000-000000000000'], $pattern->match('/p/0199f000-0000-7000-8000-000000000000.html'));
        self::assertNull($pattern->match('/p/0199f000-0000-7000-8000-000000000000xhtml'), 'Dots are literal.');
        self::assertSame('/p', $pattern->staticPrefix());
        self::assertSame('/p/*.html', $pattern->signature());
    }

    public function testSignaturesShowWhichPatternsCompete(): void
    {
        $signature = static fn (string $pattern): string => PermalinkPattern::parse($pattern, hierarchical: true)->signature();

        self::assertSame($signature('/blog/{slug}'), $signature('/blog/{id}'));
        self::assertNotSame($signature('/blog/{slug}'), $signature('/news/{slug}'));
        self::assertNotSame($signature('/{slug}'), $signature('/{path}'), 'Overlapping, but resolved by order: the single segment first.');
        self::assertSame('/**', $signature('/{path}'));
    }

    public function testMoreSpecificPatternsComeFirst(): void
    {
        $patterns = ['/{path}', '/{slug}', '/blog/{slug}', '/blog/{year}/{slug}', '/{year}/{month}/{slug}'];
        usort($patterns, static fn (string $a, string $b): int => PermalinkPattern::parse($b, true)->specificity() <=> PermalinkPattern::parse($a, true)->specificity());

        self::assertSame(['/blog/{year}/{slug}', '/blog/{slug}', '/{year}/{month}/{slug}', '/{slug}', '/{path}'], $patterns);
    }

    public function testStaticPrefix(): void
    {
        self::assertSame('/blog', PermalinkPattern::parse('/blog/{slug}', false)->staticPrefix());
        self::assertSame('/docs/v2', PermalinkPattern::parse('/docs/v2/{path}', true)->staticPrefix());
        self::assertSame('', PermalinkPattern::parse('/{year}/{slug}', false)->staticPrefix());
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'no leading slash' => ['blog/{slug}', false, 'must start with "/"'];
        yield 'uppercase' => ['/Blog/{slug}', false, 'must start with "/"'];
        yield 'trailing slash' => ['/blog/{slug}/', false, 'must start with "/"'];
        yield 'unknown placeholder' => ['/blog/{title}', false, 'unknown placeholder {title}'];
        yield 'repeated placeholder' => ['/{slug}/{slug}', false, 'more than once'];
        yield 'nothing identifying' => ['/blog/{year}', false, 'needs {slug}, {path} or {id}'];
        yield 'path without hierarchy' => ['/{path}', false, 'only hierarchical types'];
        yield 'path inside a segment' => ['/docs-{path}', true, '{path} on its own'];
    }

    #[DataProvider('invalidPatterns')]
    public function testRejectsInvalidPatterns(string $pattern, bool $hierarchical, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        PermalinkPattern::parse($pattern, $hierarchical);
    }
}
