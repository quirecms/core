<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use League\CommonMark\Extension\Footnote\FootnoteExtension;
use Quire\Extend\ExtenderContext;
use Quire\Extend\Formatter;
use Quire\Formatter\Format\MarkdownFormat;
use Quire\Formatter\Format\TextFormat;
use Quire\Formatter\Formatter as Service;
use Quire\Tests\Extend\Fixtures\FixtureCommand;

final class FormatterTest extends ExtenderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        new Formatter()
            ->format('markdown', MarkdownFormat::class)
            ->profile('content', formats: ['markdown'], shortcodes: ['*'])
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));
    }

    public function testRegistersFormatsProfilesAndServices(): void
    {
        $this->apply(new Formatter()
            ->format('plain', TextFormat::class, profiles: ['content'])
            ->commonMark(FootnoteExtension::class, profiles: ['content'])
            ->profile('review', formats: ['markdown', 'plain'], sanitizer: 'basic', linkRel: ['nofollow']));

        $config = $this->config();

        self::assertSame(['markdown', 'plain'], $config['profiles']['content']['formats']);
        self::assertSame(['markdown', 'plain'], $config['profiles']['review']['formats']);
        self::assertSame('acme-reviews', $config['profiles']['review']['owner']);
        self::assertSame([['class' => FootnoteExtension::class, 'profiles' => ['content'], 'owner' => 'acme-reviews']], $config['commonmark']);
        self::assertTrue($this->container->getDefinition(FootnoteExtension::class)->hasTag(Service::SERVICE_TAG));
    }

    public function testInvalidRegistrations(): void
    {
        $this->assertExtenderFails(new Formatter()->format('Mark Down', TextFormat::class), 'Names start with a lowercase letter');
        $this->assertExtenderFails(new Formatter()->format('markdown', TextFormat::class), 'The format is already added by "core".');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new Formatter()->format('plain', 'Acme\Missing'), 'The class "Acme\Missing" doesn\'t exist.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new Formatter()->shortcode('gallery', FixtureCommand::class), 'must implement Quire\Formatter\ShortcodeInterface.');
        $this->assertExtenderFails(new Formatter()->format('plain', TextFormat::class, profiles: ['comment']), 'Unknown profile "comment". Known: content.');
        $this->assertExtenderFails(new Formatter()->profile('content', formats: ['markdown']), 'The profile is already added by "core".');
        $this->assertExtenderFails(new Formatter()->profile('review', formats: []), 'A profile needs at least one format.');
        $this->assertExtenderFails(new Formatter()->profile('review', formats: ['asciidoc']), 'Unknown format "asciidoc". Known: markdown.');
        $this->assertExtenderFails(new Formatter()->profile('review', formats: ['markdown'], sanitizer: 'strict'), 'The sanitizer is "rich" or "basic".');
        $this->assertExtenderFails(new Formatter()->profile('review', formats: ['markdown'], linkRel: ['no follow']), 'Link rel values are single lowercase words');
    }

    /**
     * @return array{profiles: array<string, array{formats: list<string>, owner: string}>, commonmark: list<array<string, mixed>>}
     */
    private function config(): array
    {
        /** @var array{profiles: array<string, array{formats: list<string>, owner: string}>, commonmark: list<array<string, mixed>>} $config */
        $config = $this->container->getParameterBag()->all()[Service::PARAMETER];

        return $config;
    }
}
