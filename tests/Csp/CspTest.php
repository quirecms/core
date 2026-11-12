<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\TestCase;
use Quire\Csp\Csp;
use Quire\Csp\Internal\FirstPartySourcesInterface;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Csp\PolicyKind;
use Quire\Csp\ThirdPartySourcesInterface;
use Symfony\Component\HttpFoundation\Request;

final class CspTest extends TestCase
{
    public function testTheSitesPagesLoadOnlyFromTheSiteItself(): void
    {
        self::assertSame(
            "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
            new Csp(new NoThirdPartySources())->policy(PolicyKind::Page)->header(),
        );
    }

    public function testPrivatePagesAreNeverFramed(): void
    {
        $policy = new Csp(new NoThirdPartySources(), siteSources: ['frame-ancestors' => ['https://partner.example']])->policy(PolicyKind::Private);

        self::assertSame(["'none'"], $policy->sources('frame-ancestors'), 'Not even where the site lets its pages be framed.');
        self::assertSame(["'self'"], $policy->sources('script-src'));
    }

    public function testPrivatePagesGetNoOtherSitesServices(): void
    {
        $csp = new Csp($this->thirdParty(['script-src' => ['https://www.googletagmanager.com']]), siteSources: ['img-src' => ['https://images.example.com']]);

        self::assertStringContainsString('https://www.googletagmanager.com', $csp->policy(PolicyKind::Page)->header());
        self::assertStringNotContainsString('https://www.googletagmanager.com', $csp->policy(PolicyKind::Private)->header(), 'A script on a password form could read the password, consent or not.');
        self::assertSame(["'self'", 'data:', 'https://images.example.com'], $csp->policy(PolicyKind::Private)->sources('img-src'), 'The site\'s own choices still apply.');
    }

    public function testStoredFilesAreSandboxedAsCaddySendsThem(): void
    {
        self::assertSame(Csp::STORED_FILE, new Csp(new NoThirdPartySources())->policy(PolicyKind::StoredFile)->header());
    }

    public function testTheAdminIsLimitedOnlyWhereItsScriptsDontCare(): void
    {
        $policy = new Csp(new NoThirdPartySources())->policy(PolicyKind::Admin);

        self::assertSame("base-uri 'self'; object-src 'none'; frame-ancestors 'self'", $policy->header());
    }

    public function testSourcesComeFromCoreQuirePrivacyAndTheSite(): void
    {
        $disks = new class implements FirstPartySourcesInterface {
            public function sources(): array
            {
                return ['img-src' => ['https://files.example-cdn.com']];
            }
        };
        $policy = new Csp($this->thirdParty(['script-src' => ['https://www.googletagmanager.com'], 'font-src' => ['https://fonts.gstatic.com']]), [$disks], siteSources: ['frame-src' => ['https://www.youtube-nocookie.com'], 'frame-ancestors' => ['https://partner.example']])
            ->policy(PolicyKind::Page);

        self::assertSame(["'self'", 'data:', 'https://files.example-cdn.com'], $policy->sources('img-src'));
        self::assertSame(["'self'", 'https://www.googletagmanager.com'], $policy->sources('script-src'));
        self::assertSame(["'self'", 'https://fonts.gstatic.com'], $policy->sources('font-src'));
        self::assertSame(["'self'", 'https://www.youtube-nocookie.com'], $policy->sources('frame-src'));
        self::assertSame(["'self'", 'https://partner.example'], $policy->sources('frame-ancestors'), 'The site may let a partner frame its pages.');
    }

    public function testQuirePrivacyCantAddKeywordsOrSchemes(): void
    {
        foreach ([['script-src' => ["'unsafe-inline'"]], ['script-src' => ['https:']], ['img-src' => ['*']], ['form-action' => ['https://collector.example']]] as $sources) {
            try {
                new Csp($this->thirdParty($sources))->policy(PolicyKind::Page);
                self::fail('Accepted: '.json_encode($sources));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheSiteCanAddKeywordsItChooses(): void
    {
        $policy = new Csp(new NoThirdPartySources(), siteSources: ['style-src' => ["'unsafe-inline'"]])->policy(PolicyKind::Page);

        self::assertSame(["'self'", "'unsafe-inline'"], $policy->sources('style-src'));
    }

    public function testReportsGoWhereTheSiteSays(): void
    {
        $csp = new Csp(new NoThirdPartySources(), reportOnly: true, reportUri: 'https://reports.example.com/csp');

        self::assertTrue($csp->reportOnly());
        self::assertSame(['https://reports.example.com/csp'], $csp->policy(PolicyKind::Page)->sources('report-uri'));
        self::assertFalse($csp->policy(PolicyKind::StoredFile)->has('report-uri'));
    }

    public function testANonceIsTheSameForTheWholeResponse(): void
    {
        $csp = new Csp(new NoThirdPartySources());
        $request = new Request();
        $nonce = $csp->nonce($request);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $nonce);
        self::assertSame($nonce, $csp->nonce($request));
        self::assertNotSame($nonce, $csp->nonce(new Request()));
    }

    /**
     * @param array<string, list<string>> $sources
     */
    private function thirdParty(array $sources): ThirdPartySourcesInterface
    {
        return new readonly class($sources) implements ThirdPartySourcesInterface {
            /**
             * @param array<string, list<string>> $sources
             */
            public function __construct(private array $sources)
            {
            }

            public function sources(): array
            {
                return $this->sources;
            }
        };
    }
}
