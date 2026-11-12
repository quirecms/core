<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quire\Csp\Csp;
use Quire\Csp\Internal\CspListener;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Csp\PolicyKind;
use Quire\Extend\Routes;
use Quire\Routing\ExtensionRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CspListenerTest extends TestCase
{
    public function testItKnowsQuiresRoutesByTheirDefault(): void
    {
        self::assertSame(ExtensionRouteLoader::QUIRE_ROUTE, new \ReflectionClassConstant(CspListener::class, 'QUIRE_ROUTE')->getValue());
    }

    public function testASitePageGetsOnePolicy(): void
    {
        $response = $this->respond($this->request());

        self::assertSame([new Csp(new NoThirdPartySources())->policy(PolicyKind::Page)->header()], $response->headers->all('content-security-policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testTheApplicationsOwnPagesAreLeftAlone(): void
    {
        $response = $this->respond(new Request(), new Response('', 200, ['Content-Security-Policy' => "default-src 'none'"]));

        self::assertSame("default-src 'none'", $response->headers->get('Content-Security-Policy'), 'Quire embedded in an application changes none of its pages.');
        self::assertFalse($this->respond(new Request())->headers->has('Content-Security-Policy'));
    }

    public function testOnlyMainRequestsArePolicied(): void
    {
        $response = new Response();
        new CspListener(new Csp(new NoThirdPartySources()))(new ResponseEvent($this->kernel(), $this->request(), HttpKernelInterface::SUB_REQUEST, $response));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testDataGetsNoPolicyButStoredFilesDo(): void
    {
        self::assertFalse($this->respond($this->request(), new JsonResponse([]))->headers->has('Content-Security-Policy'));
        self::assertSame(Csp::STORED_FILE, $this->respond($this->request([Csp::ATTRIBUTE => 'stored-file']), new Response('', 200, ['Content-Type' => 'image/svg+xml']))->headers->get('Content-Security-Policy'));
    }

    public function testTheAdminsPagesGetTheAdminsPolicy(): void
    {
        self::assertSame("base-uri 'self'; object-src 'none'; frame-ancestors 'self'", $this->respond($this->request([Routes::GROUP_ATTRIBUTE => Routes::ADMIN]))->headers->get('Content-Security-Policy'));
    }

    public function testARouteCanAskForThePrivatePolicy(): void
    {
        self::assertStringEndsWith("frame-ancestors 'none'", (string) $this->respond($this->request([Csp::ATTRIBUTE => 'private']))->headers->get('Content-Security-Policy'));
    }

    public function testAnUnknownPolicyIsAMistakeOfTheRoute(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The route "acme_page" asks for the Content-Security-Policy "strict". Policies: page, private, stored-file, admin.');

        $this->respond($this->request([Csp::ATTRIBUTE => 'strict', '_route' => 'acme_page']));
    }

    public function testAPolicySetElsewhereIsReplacedAndSaidSo(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = strtr((string) $message, ['{route}' => \is_string($context['route'] ?? null) ? $context['route'] : '', '{header}' => \is_string($context['header'] ?? null) ? $context['header'] : '']);
            }
        };
        $response = $this->respond($this->request(['_route' => 'acme_page']), new Response('', 200, ['Content-Security-Policy' => '*', 'Content-Security-Policy-Report-Only' => '*']), logger: $logger);

        self::assertCount(1, $response->headers->all('content-security-policy'));
        self::assertStringStartsWith("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        self::assertCount(2, $logger->warnings);
        self::assertStringContainsString('"acme_page" had its own Content-Security-Policy', $logger->warnings[0]);
    }

    public function testANonceIsAllowedForScriptsAndStylesAndTheResponseIsntCached(): void
    {
        $csp = new Csp(new NoThirdPartySources());
        $request = $this->request();
        $nonce = $csp->nonce($request);
        $response = $this->respond($request, csp: $csp);
        $header = (string) $response->headers->get('Content-Security-Policy');

        self::assertStringContainsString(\sprintf("script-src 'self' 'nonce-%s';", $nonce), $header);
        self::assertStringContainsString(\sprintf("style-src 'self' 'nonce-%s';", $nonce), $header);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testANonceDoesntLimitWhatThePolicyLeftOpen(): void
    {
        $csp = new Csp(new NoThirdPartySources());
        $request = $this->request([Routes::GROUP_ATTRIBUTE => Routes::ADMIN]);
        $csp->nonce($request);

        self::assertStringNotContainsString('nonce', (string) $this->respond($request, csp: $csp)->headers->get('Content-Security-Policy'), 'The admin\'s scripts and styles stay unlimited.');
    }

    public function testReportOnlyTriesThePagesPolicyButKeepsPasswordFormsUnframed(): void
    {
        $csp = new Csp(new NoThirdPartySources(), reportOnly: true);

        $page = $this->respond($this->request(), csp: $csp);
        self::assertFalse($page->headers->has('Content-Security-Policy'));
        self::assertStringStartsWith("default-src 'self'", (string) $page->headers->get('Content-Security-Policy-Report-Only'));

        $private = $this->respond($this->request([Csp::ATTRIBUTE => 'private']), csp: $csp);
        self::assertSame("frame-ancestors 'none'", $private->headers->get('Content-Security-Policy'));
        self::assertStringEndsWith("frame-ancestors 'none'", (string) $private->headers->get('Content-Security-Policy-Report-Only'));

        self::assertSame(Csp::STORED_FILE, $this->respond($this->request([Csp::ATTRIBUTE => 'stored-file']), csp: $csp)->headers->get('Content-Security-Policy'), 'Uploads are always sandboxed.');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes = []): Request
    {
        return new Request(attributes: [ExtensionRouteLoader::QUIRE_ROUTE => true, ...$attributes]);
    }

    private function respond(Request $request, ?Response $response = null, ?Csp $csp = null, ?AbstractLogger $logger = null): Response
    {
        $response ??= new Response('<!doctype html><title>Page</title>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $listener = $logger instanceof AbstractLogger ? new CspListener($csp ?? new Csp(new NoThirdPartySources()), $logger) : new CspListener($csp ?? new Csp(new NoThirdPartySources()));
        $listener(new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function kernel(): HttpKernelInterface
    {
        return self::createStub(HttpKernelInterface::class);
    }
}
