<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Quire\Theme\Twig\TranslationRuntime;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Markup;

/**
 * ARC-0063: a translation is text, escaped like any; only the HTML the code made goes in as it is.
 */
final class TranslationRuntimeTest extends TestCase
{
    public function testTheCodesHtmlGoesInAndTheTranslationIsEscaped(): void
    {
        $html = $this->runtime(['saved {when}' => 'gespeichert <b>{when}</b>'])
            ->transHtml('saved {when}', ['when' => new Markup('<time datetime="2026-11-18T10:00:00+00:00">10:00</time>', 'UTF-8')], 'quire-admin');

        self::assertSame('gespeichert &lt;b&gt;<time datetime="2026-11-18T10:00:00+00:00">10:00</time>&lt;/b&gt;', (string) $html);
    }

    public function testATranslationWithScriptIsText(): void
    {
        $html = $this->runtime(['Invited {when}' => '<script>alert(1)</script>{when}<img src=x onerror=alert(2)>'])
            ->transHtml('Invited {when}', ['when' => new Markup('<time>Tue</time>', 'UTF-8')], 'quire-admin');

        self::assertStringNotContainsString('<script', (string) $html);
        self::assertStringNotContainsString('<img', (string) $html);
        self::assertStringContainsString('&lt;script&gt;', (string) $html);
        self::assertStringContainsString('<time>Tue</time>', (string) $html);
    }

    public function testAParameterThatIsntMarkupIsEscapedToo(): void
    {
        $html = $this->runtime(['{name}’s version' => '{name}’s version'])->transHtml('{name}’s version', ['name' => '<i>Ada</i>'], 'quire-admin');

        self::assertSame('&lt;i&gt;Ada&lt;/i&gt;’s version', (string) $html);
    }

    public function testAParameterUsedTwiceOrNotAtAll(): void
    {
        $runtime = $this->runtime(['at {when}' => '{when} · {when}', 'Saved' => 'Gespeichert']);
        $time = new Markup('<time>9</time>', 'UTF-8');

        self::assertSame('<time>9</time> · <time>9</time>', (string) $runtime->transHtml('at {when}', ['when' => $time], 'quire-admin'));
        self::assertSame('Gespeichert', (string) $runtime->transHtml('Saved', ['when' => $time], 'quire-admin'));
    }

    public function testATranslationThatWritesTheMarkGetsOnlyTheCodesHtml(): void
    {
        // A translator who guesses the mark can only repeat the code's own markup, never add any
        $html = $this->runtime(['at {when}' => "\u{E000}0\u{E000}<u>"])->transHtml('at {when}', ['when' => new Markup('<time>9</time>', 'UTF-8')], 'quire-admin');

        self::assertSame('<time>9</time>&lt;u&gt;', (string) $html);
    }

    /**
     * @param array<string, string> $messages German translations of the quire-admin domain
     */
    private function runtime(array $messages): TranslationRuntime
    {
        $translator = new Translator('de');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', $messages, 'de', 'quire-admin+intl-icu');

        return new TranslationRuntime($translator);
    }
}
