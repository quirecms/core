<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Quire\Theme\SlotRendererInterface;
use Quire\Theme\Slots;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SlotsTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAFailingFragmentIsLeftOutAndLoggedSoThePageStillRenders(): void
    {
        $html = $this->slots(debug: false)->render('footer', ['name' => 'Ada']);

        self::assertSame('<p>Hi Ada</p><p>Last</p>', $html, 'No source comments outside debug mode.');
        self::assertSame(['The fragment of "acme-broken" in the slot "footer" failed, so it was left out: Out of order.'], $this->logger->messages);
    }

    public function testInDebugModeAFailingFragmentShowsItsError(): void
    {
        $this->expectExceptionMessageIsOrContains('Out of order.');

        $this->slots(debug: true)->render('footer');
    }

    public function testDescribesWhatFillsASlot(): void
    {
        $slots = $this->slots(debug: false);

        self::assertTrue($slots->has('footer'));
        self::assertFalse($slots->has('sidebar'));
        self::assertSame('', $slots->render('sidebar'));
        self::assertSame([
            ['owner' => 'acme-hello', 'fragment' => 'hello.html.twig'],
            ['owner' => 'acme-broken', 'fragment' => BrokenRenderer::class],
            ['owner' => 'acme-last', 'fragment' => 'last.html.twig'],
        ], $slots->fragments('footer'));
    }

    private function slots(bool $debug): Slots
    {
        return new Slots(
            new Environment(new ArrayLoader(['hello.html.twig' => '<p>Hi {{ name }}</p>', 'last.html.twig' => '<p>Last</p>'])),
            [
                ['slot' => 'footer', 'template' => 'hello.html.twig', 'class' => null, 'priority' => 10, 'owner' => 'acme-hello'],
                ['slot' => 'footer', 'template' => null, 'class' => BrokenRenderer::class, 'priority' => 5, 'owner' => 'acme-broken'],
                ['slot' => 'footer', 'template' => 'last.html.twig', 'class' => null, 'priority' => 0, 'owner' => 'acme-last'],
            ],
            new ServiceLocator([BrokenRenderer::class => static fn (): BrokenRenderer => new BrokenRenderer()]),
            $this->logger,
            $debug,
        );
    }
}

final class BrokenRenderer implements SlotRendererInterface
{
    public function render(array $context): string
    {
        throw new \RuntimeException('Out of order.');
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> Messages with their placeholders filled in */
    public array $messages = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = \is_scalar($value) ? (string) $value : '';
        }

        $this->messages[] = strtr((string) $message, $replacements);
    }
}
