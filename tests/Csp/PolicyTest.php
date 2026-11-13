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

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function allowed(): iterable
    {
        yield 'the host' => ['https://player.video.example', 'https://player.video.example/embed/1', true];
        yield 'another host' => ['https://player.video.example', 'https://video.example/embed/1', false];
        yield 'a host that only ends the same' => ['https://video.example', 'https://evil-video.example/x', false];
        yield 'upper case' => ['https://player.video.example', 'HTTPS://PLAYER.Video.EXAMPLE/x', true];
        yield 'a trailing dot' => ['https://player.video.example', 'https://player.video.example./x', true];
        yield 'userinfo' => ['https://player.video.example', 'https://evil.example@player.video.example/x', true];
        yield 'userinfo naming the host' => ['https://player.video.example', 'https://player.video.example@evil.example/x', false];
        yield 'a subdomain under *.' => ['https://*.video.example', 'https://a.b.video.example/x', true];
        yield 'not the domain itself under *.' => ['https://*.video.example', 'https://video.example/x', false];
        yield 'http allows https' => ['http://cdn.example', 'https://cdn.example/x', true];
        yield 'https doesn\'t allow http' => ['https://cdn.example', 'http://cdn.example/x', false];
        yield 'the default port' => ['https://cdn.example', 'https://cdn.example:443/x', true];
        yield 'another port' => ['https://cdn.example', 'https://cdn.example:8443/x', false];
        yield 'the port given' => ['https://cdn.example:8443', 'https://cdn.example:8443/x', true];
        yield 'a path prefix' => ['https://cdn.example/libs/', 'https://cdn.example/libs/a.js', true];
        yield 'outside the path' => ['https://cdn.example/libs/', 'https://cdn.example/other/a.js', false];
        yield 'an exact path' => ['https://cdn.example/a.js', 'https://cdn.example/a.js', true];
        yield 'an international name' => ['https://xn--bcher-kva.example', 'https://bücher.example/x', true];
        yield 'a keyword never' => ["'self'", 'https://cdn.example/x', false];
        yield 'not a URL' => ['https://cdn.example', 'cdn.example/x', false];
    }

    #[DataProvider('allowed')]
    public function testASourceAllowsAUrlAsBrowsersMatch(string $source, string $url, bool $allowed): void
    {
        self::assertSame($allowed, Policy::allows($source, $url));
    }
}
