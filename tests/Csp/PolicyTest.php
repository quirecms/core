<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Csp\Policy;

final class PolicyTest extends TestCase
{
    public function testTheHeaderKeepsTheDirectivesInOrder(): void
    {
        $policy = new Policy(['default-src' => ["'self'"], 'img-src' => ["'self'", 'data:'], 'sandbox' => []]);

        self::assertSame("default-src 'self'; img-src 'self' data:; sandbox", $policy->header());
        self::assertSame($policy->header(), (string) $policy);
    }

    public function testSourcesAreAddedOnceAfterTheDirectivesOwn(): void
    {
        $policy = new Policy(['img-src' => ["'self'"]])
            ->with('img-src', 'https://images.example.com', "'self'", 'https://images.example.com');

        self::assertSame(["'self'", 'https://images.example.com'], $policy->sources('img-src'));
    }

    public function testAMissingFetchDirectiveStartsFromDefaultSrc(): void
    {
        $policy = new Policy(['default-src' => ["'self'"], 'base-uri' => ["'self'"]]);

        self::assertSame(["'self'", 'https://fonts.example.com'], $policy->with('font-src', 'https://fonts.example.com')->sources('font-src'), 'Fonts from the site itself still load.');
        self::assertSame(['https://partner.example'], $policy->with('form-action', 'https://partner.example')->sources('form-action'), 'Navigation directives don\'t fall back.');
    }

    public function testNoneGivesWayToARealSource(): void
    {
        self::assertSame(['https://www.youtube-nocookie.com'], new Policy(['frame-src' => ["'none'"]])->with('frame-src', 'https://www.youtube-nocookie.com')->sources('frame-src'));
        self::assertSame(["'self'"], new Policy(['img-src' => ["'self'"]])->with('img-src', "'none'")->sources('img-src'), "'none' never removes a source.");
    }

    public function testItIsImmutable(): void
    {
        $policy = new Policy(['img-src' => ["'self'"]]);
        $policy->with('img-src', 'https://images.example.com');

        self::assertSame(["'self'"], $policy->sources('img-src'));
        self::assertFalse($policy->has('script-src'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a second directive' => ['img-src', "https://a.example; script-src 'unsafe-inline'"];
        yield 'a second policy' => ['img-src', 'https://a.example, script-src *'];
        yield 'a space' => ['img-src', 'https://a.example https://b.example'];
        yield 'a line break' => ['img-src', "https://a.example\r\nSet-Cookie: x=1"];
        yield 'an unquoted keyword' => ['script-src', 'unsafe-inline'];
        yield 'an unknown keyword' => ['script-src', "'unsafe-everything'"];
        yield 'a quote inside' => ['img-src', "https://a.example'"];
        yield 'a sandbox flag that isn\'t one' => ['sandbox', 'allow-scripts; script-src *'];
    }

    #[DataProvider('invalid')]
    public function testWhatIsntASourceIsRefused(string $directive, string $source): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Policy()->with($directive, $source);
    }

    public function testAnUnknownDirectiveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"script-source" isn\'t a Content-Security-Policy directive');

        new Policy(['script-source' => ["'self'"]]);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hosts(): iterable
    {
        yield 'a host' => ['https://cdn.example.com', true];
        yield 'subdomains' => ['https://*.google-analytics.com', true];
        yield 'a port and a path' => ['https://cdn.example.com:8443/fonts/', true];
        yield 'a local service' => ['http://localhost:9000', true];
        yield 'websockets' => ['wss://live.example.com', true];
        yield 'self' => ["'self'", false];
        yield 'a keyword' => ["'unsafe-inline'", false];
        yield 'a scheme alone, which allows every site' => ['https:', false];
        yield 'everything' => ['*', false];
        yield 'every host' => ['https://*', false];
        yield 'no scheme' => ['cdn.example.com', false];
        yield 'a nonce' => ["'nonce-abc'", false];
    }

    #[DataProvider('hosts')]
    public function testWhatIsAHost(string $source, bool $host): void
    {
        self::assertSame($host, Policy::isHost($source));
    }
}
