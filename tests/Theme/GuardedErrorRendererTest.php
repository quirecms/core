<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quire\Http\ErrorReference;
use Quire\Theme\Internal\GuardedErrorRenderer;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A theme's error page that fails never turns a 404 into a 500.
 */
final class GuardedErrorRendererTest extends TestCase
{
    public function testAFailingErrorPageIsAPlainOneWithItsOwnStatus(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (\is_string($level) ? $level : '').': '.strtr((string) $message, ['{failure}' => \is_string($context['failure'] ?? null) ? $context['failure'] : '']);
            }
        };
        $failing = new class implements ErrorRendererInterface {
            public function render(\Throwable $exception): FlattenException
            {
                throw new \RuntimeException('The latest posts query failed.');
            }
        };

        $page = new GuardedErrorRenderer($failing, $logger, new ErrorReference())->render(new NotFoundHttpException('No route.'));

        self::assertSame(404, $page->getStatusCode(), 'Still "not found", never a 500.');
        self::assertStringContainsString('<h1>Page not found</h1>', $page->getAsString());
        self::assertSame(['error: The theme\'s error page failed: The latest posts query failed.. A plain page was sent instead.'], $logger->logged);
    }

    public function testThePlainPageOfAServerErrorStillGivesItsReference(): void
    {
        $failing = new class implements ErrorRendererInterface {
            public function render(\Throwable $exception): FlattenException
            {
                throw new \RuntimeException('The theme broke too.');
            }
        };
        $reference = new ErrorReference();
        $code = $reference->make();

        $page = new GuardedErrorRenderer($failing, new \Psr\Log\NullLogger(), $reference)->render(new \RuntimeException('The database is down'));

        self::assertSame(500, $page->getStatusCode());
        self::assertStringContainsString('<code>'.$code.'</code>', $page->getAsString(), 'What the owner finds in the log.');
    }

    public function testAWorkingErrorPageIsLeftAsItIs(): void
    {
        $working = new class implements ErrorRendererInterface {
            public function render(\Throwable $exception): FlattenException
            {
                return FlattenException::createFromThrowable($exception)->setAsString('<p>The theme\'s page</p>');
            }
        };

        self::assertSame('<p>The theme\'s page</p>', new GuardedErrorRenderer($working, new \Psr\Log\NullLogger(), new ErrorReference())->render(new NotFoundHttpException())->getAsString());
    }
}
