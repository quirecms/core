<?php

declare(strict_types=1);

namespace Quire\Formatter\Format;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Psr\Container\ContainerInterface;
use Quire\Formatter\FormatContext;
use Quire\Formatter\FormatInterface;
use Quire\Formatter\Formatter;
use Quire\Formatter\FormatterProfile;
use Quire\Formatter\Internal\ShortcodeInlineParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * CommonMark with GitHub's extensions (tables, task lists, strikethrough, autolinks), plus the
 * CommonMark extensions registered for the profile.
 */
final class MarkdownFormat implements FormatInterface, ResetInterface
{
    /** @var array<string, array{MarkdownConverter, ShortcodeInlineParser}> Per profile */
    private array $converters = [];

    /**
     * @param array{commonmark: list<array{class: string, profiles: ?list<string>, owner: string}>} $config
     */
    public function __construct(
        #[Autowire(param: Formatter::PARAMETER)]
        private readonly array $config,
        #[AutowireLocator(Formatter::SERVICE_TAG)]
        private readonly ContainerInterface $services,
    ) {
    }

    public function toHtml(string $source, FormatContext $context): string
    {
        [$converter, $shortcodes] = $this->converters[$context->profile->name] ??= $this->converter($context->profile);

        $shortcodes->using($context);

        try {
            return $converter->convert($source)->getContent();
        } finally {
            $shortcodes->using(null);
        }
    }

    public function reset(): void
    {
        $this->converters = [];
    }

    /**
     * @return array{MarkdownConverter, ShortcodeInlineParser}
     */
    private function converter(FormatterProfile $profile): array
    {
        $environment = new Environment([
            // Raw HTML is escaped unless the profile allows it; it's sanitised either way.
            'html_input' => $profile->rawHtml ? 'allow' : 'escape',
            'allow_unsafe_links' => false,
            // Limits against pathological input that makes parsers slow.
            'max_nesting_level' => 50,
            'max_delimiters_per_line' => 1000,
            'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'id_prefix' => '', 'fragment_prefix' => ''],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        if ($profile->headingAnchors) {
            $environment->addExtension(new HeadingPermalinkExtension());
        }

        foreach ($this->config['commonmark'] as $extension) {
            if (null === $extension['profiles'] || \in_array($profile->name, $extension['profiles'], true)) {
                $instance = $this->services->get($extension['class']);
                \assert($instance instanceof ExtensionInterface);
                $environment->addExtension($instance);
            }
        }

        $shortcodes = new ShortcodeInlineParser();
        // Before CommonMark's own "[" parsers, which would read the brackets as a link.
        $environment->addInlineParser($shortcodes, 200);

        return [new MarkdownConverter($environment), $shortcodes];
    }
}
