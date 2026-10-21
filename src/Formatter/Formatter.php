<?php

declare(strict_types=1);

namespace Quire\Formatter;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Quire\Content\Content;
use Quire\Formatter\Exception\UnknownFormatException;
use Quire\Formatter\Internal\Sanitizers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns text into safe HTML:
 *
 *     source → pre-processors → format (Markdown, text, HTML, …) → post-processors → sanitiser
 *
 * {@see compile()} does that and keeps shortcodes as placeholders; {@see expand()} renders them. Compiled
 * text can be cached, since shortcodes run on every expansion. {@see render()} does both.
 *
 * @api
 */
final class Formatter implements ResetInterface
{
    public const string PARAMETER = 'quire.formatter';

    /** Services the formatter uses: formats, shortcodes, CommonMark extensions and processors. */
    public const string SERVICE_TAG = 'quire.formatter.service';

    private const string PLACEHOLDER = '/'.FormatContext::PLACEHOLDER_START.'(\d+)'.FormatContext::PLACEHOLDER_END.'/u';

    /** @var array<string, FormatterProfile> */
    private array $profiles;

    /** @var array<string, HtmlSanitizerInterface> */
    private array $sanitizers = [];

    /**
     * @param array{formats: array<string, array{class: string, owner: string}>, profiles: array<string, array<string, mixed>>, shortcodes: array<string, array{class: string, trusted: bool, owner: string}>, commonmark: list<array{class: string, profiles: ?list<string>, owner: string}>, pre: list<array{class: string, profiles: ?list<string>, owner: string}>, post: list<array{class: string, profiles: ?list<string>, owner: string}>} $config Compiled by {@see \Quire\Extend\Formatter}
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        private readonly array $config,
        #[AutowireLocator(self::SERVICE_TAG)]
        private readonly ContainerInterface $services,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
    ) {
        $this->profiles = array_map(FormatterProfile::fromArray(...), $config['profiles']);
    }

    /**
     * @throws UnknownFormatException
     */
    public function profile(string $name): FormatterProfile
    {
        return $this->profiles[$name] ?? throw new UnknownFormatException(\sprintf('No formatter profile "%s" exists. Known: %s.', $name, implode(', ', array_keys($this->profiles))));
    }

    /**
     * @return array<string, FormatterProfile>
     */
    public function profiles(): array
    {
        return $this->profiles;
    }

    /**
     * @return list<string> Every registered format
     */
    public function formats(): array
    {
        return array_keys($this->config['formats']);
    }

    /**
     * @throws UnknownFormatException when the profile doesn't allow the format
     */
    public function compile(string $source, string $format, string $profile = 'content', ?Content $content = null): CompiledText
    {
        $context = $this->context($format, $profile, $content);

        // Placeholders can't be forged: their marker characters are removed from the source.
        $source = str_replace([FormatContext::PLACEHOLDER_START, FormatContext::PLACEHOLDER_END], '', $source);

        foreach ($this->processors('pre', $context->profile) as $processor) {
            \assert($processor instanceof SourceProcessorInterface);
            $source = $processor->process($source, $context);
        }

        $formatter = $this->services->get($this->config['formats'][$format]['class']);
        \assert($formatter instanceof FormatInterface);
        $html = $formatter->toHtml($source, $context);

        foreach ($this->processors('post', $context->profile) as $processor) {
            \assert($processor instanceof HtmlProcessorInterface);
            $html = $processor->process($html, $context);
        }

        return new CompiledText($this->sanitizer($context->profile)->sanitize($html), $context->shortcodes(), $context->profile->name, $format);
    }

    /**
     * Renders the shortcodes of compiled text into it.
     */
    public function expand(CompiledText $compiled, ?Content $content = null): string
    {
        if ([] === $compiled->shortcodes) {
            return $compiled->html;
        }

        $context = $this->context($compiled->format, $compiled->profile, $content);
        $rendered = [];
        $render = function (int $index) use ($compiled, $context, &$rendered): string {
            return $rendered[$index] ??= $this->renderShortcode($compiled->shortcodes[$index] ?? null, $context);
        };

        // A shortcode alone in a paragraph replaces the paragraph, so block output (a gallery) stays valid HTML.
        $html = (string) preg_replace_callback(
            '#<p>\s*'.FormatContext::PLACEHOLDER_START.'(\d+)'.FormatContext::PLACEHOLDER_END.'\s*</p>#u',
            static fn (array $match): string => $render((int) $match[1]),
            $compiled->html,
        );

        // Placeholders are only replaced in text, never inside a tag, where output could break out of an attribute.
        $parts = preg_split('/(<[^>]*>)/', $html, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $parts = false === $parts ? [$html] : $parts;

        foreach ($parts as $i => $part) {
            $parts[$i] = str_starts_with($part, '<')
                ? (string) preg_replace(self::PLACEHOLDER, '', $part)
                : (string) preg_replace_callback(self::PLACEHOLDER, static fn (array $match): string => $render((int) $match[1]), $part);
        }

        return implode('', $parts);
    }

    /**
     * Compiles and expands in one step, for text that isn't cached.
     *
     * @throws UnknownFormatException
     */
    public function render(string $source, string $format, string $profile = 'content', ?Content $content = null): string
    {
        return $this->expand($this->compile($source, $format, $profile, $content), $content);
    }

    public function reset(): void
    {
        $this->sanitizers = [];
    }

    private function context(string $format, string $profileName, ?Content $content): FormatContext
    {
        $profile = $this->profile($profileName);

        if (!isset($this->config['formats'][$format]) || !\in_array($format, $profile->formats, true)) {
            throw new UnknownFormatException(\sprintf('The "%s" profile doesn\'t allow the format "%s". Use one of: %s.', $profile->name, $format, implode(', ', $profile->formats)));
        }

        $shortcodes = array_values(array_filter(array_keys($this->config['shortcodes']), $profile->allowsShortcode(...)));

        return new FormatContext($profile, $format, $content, $shortcodes);
    }

    private function renderShortcode(?Shortcode $shortcode, FormatContext $context): string
    {
        $registration = $shortcode instanceof Shortcode ? $this->config['shortcodes'][$shortcode->name] ?? null : null;

        if (!$shortcode instanceof Shortcode || null === $registration) {
            // Cached text whose shortcode's extension was disabled since.
            return '';
        }

        try {
            $handler = $this->services->get($registration['class']);
            \assert($handler instanceof ShortcodeInterface);
            $html = $handler->render($shortcode, $context);
        } catch (\Throwable $e) {
            if ($this->debug) {
                throw $e;
            }

            // One broken shortcode shouldn't take the page down with it.
            $this->logger?->error('The shortcode {shortcode} failed: {message}', ['shortcode' => $shortcode->source, 'message' => $e->getMessage(), 'exception' => $e]);

            return '';
        }

        return $registration['trusted'] ? $html : $this->sanitizer($context->profile)->sanitize($html);
    }

    /**
     * @param 'pre'|'post' $kind
     *
     * @return iterable<mixed>
     */
    private function processors(string $kind, FormatterProfile $profile): iterable
    {
        foreach ($this->config[$kind] as $processor) {
            if (null === $processor['profiles'] || \in_array($profile->name, $processor['profiles'], true)) {
                yield $this->services->get($processor['class']);
            }
        }
    }

    private function sanitizer(FormatterProfile $profile): HtmlSanitizerInterface
    {
        return $this->sanitizers[$profile->name] ??= Sanitizers::for($profile);
    }
}
