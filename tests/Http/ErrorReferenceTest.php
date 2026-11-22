<?php

declare(strict_types=1);

namespace Quire\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quire\Http\ErrorReference;
use Quire\Http\EventListener\ErrorReferenceListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ErrorReferenceTest extends TestCase
{
    public function testAServerErrorIsLoggedWithTheReferenceThePageShows(): void
    {
        self::assertNull(new ErrorReference()->current(), 'A page that didn\'t fail has none.');

        $reference = new ErrorReference();
        $logger = new RecordingLogger();
        new ErrorReferenceListener($reference, $logger)($this->event(new \RuntimeException('The database is down')));

        $code = $reference->current()['code'] ?? '';
        self::assertMatchesRegularExpression('/^[0-9a-f]{4}-[0-9a-f]{4}$/', $code);
        self::assertCount(1, $logger->records);
        self::assertSame('Error reference {reference}: {class}: {message}', $logger->records[0]['message']);
        $context = $logger->records[0]['context'];
        self::assertSame([$code, \RuntimeException::class, 'The database is down'], [$context['reference'] ?? null, $context['class'] ?? null, $context['message'] ?? null], 'The line the owner finds in the log by the reference.');

        new ErrorReferenceListener($reference, $logger)($this->event(new ServiceUnavailableHttpException()));
        self::assertSame($code, $reference->current()['code'] ?? null, 'One reference per request, however many failures.');
    }

    public function testOnlyServerErrorsGetOne(): void
    {
        $reference = new ErrorReference();
        $logger = new RecordingLogger();
        new ErrorReferenceListener($reference, $logger)($this->event(new NotFoundHttpException()));

        self::assertNull($reference->current(), 'Nothing to look up for a 404.');
        self::assertSame([], $logger->records);
    }

    public function testEachRequestHasItsOwn(): void
    {
        $reference = new ErrorReference();
        $first = $reference->make();
        $reference->reset();

        self::assertNull($reference->current(), 'A worker\'s next request starts without one.');
        self::assertNotSame($first, $reference->make());
    }

    private function event(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
            {
                throw new \LogicException('Not called.');
            }
        }, new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}

/**
 * The lines logged, as given.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
