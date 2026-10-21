<?php

declare(strict_types=1);

namespace Quire\Content;

use Quire\Formatter\CompiledText;
use Quire\Formatter\Formatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Renders content bodies, teasers and excerpts, with a cache.
 *
 * The cache key includes the item's version and a fingerprint of the formatter setup, so saving an
 * item or changing extensions never shows stale HTML. Shortcodes aren't cached: they render on each call.
 * The cache is disposable: clear it any time with `bin/console cache:pool:clear quire.render_cache`.
 *
 * @api
 */
final readonly class ContentRenderer
{
    public const string CACHE_POOL = 'quire.render_cache';

    /** Ends the teaser: the part of the body shown in listings. */
    private const string MORE_MARKER = '/^[ \t]*<!--\s*more\s*-->[ \t]*$/mi';

    public function __construct(
        private Formatter $formatter,
        #[Autowire(service: self::CACHE_POOL)]
        private CacheInterface $cache,
        #[Autowire(param: 'quire.formatter.fingerprint')]
        private string $fingerprint,
        #[Autowire(param: 'kernel.debug')]
        private bool $debug = false,
    ) {
    }

    /**
     * The body as HTML, ready to print unescaped.
     */
    public function body(Content $content, string $profile = 'content'): string
    {
        return $this->formatter->expand($this->compiled($content, $profile)['body'], $content);
    }

    /**
     * The part of the body before a `<!--more-->` line, as HTML, or null when the body has no such line.
     */
    public function teaser(Content $content, string $profile = 'content'): ?string
    {
        $teaser = $this->compiled($content, $profile)['teaser'];

        return null === $teaser ? null : $this->formatter->expand($teaser, $content);
    }

    /**
     * A plain-text summary (escape it when printing): the item's excerpt, or the start of its teaser or
     * body, cut at a word boundary.
     */
    public function excerpt(Content $content, int $length = 200, string $profile = 'content'): string
    {
        $compiled = $this->compiled($content, $profile);
        $text = $content->excerpt ?? html_entity_decode(strip_tags(($compiled['teaser'] ?? $compiled['body'])->htmlWithoutShortcodes()), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, false === $space || $space < $length / 2 ? $length : $space), " \t,.;:!?-").'…';
    }

    /**
     * @return array{body: CompiledText, teaser: ?CompiledText}
     */
    private function compiled(Content $content, string $profile): array
    {
        if ($this->debug) {
            // In development, formatter code changes all the time.
            return $this->compile($content, $profile);
        }

        $key = \sprintf('content.%s.%d.%s.%s', str_replace('-', '', $content->id), $content->version, $profile, $this->fingerprint);

        /** @var array{body: array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string}, teaser: ?array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string}} $cached */
        $cached = $this->cache->get($key, function (ItemInterface $item) use ($content, $profile): array {
            // Entries for older versions are never read again; let them expire.
            $item->expiresAfter(30 * 24 * 3600);
            $compiled = $this->compile($content, $profile);

            return ['body' => $compiled['body']->toArray(), 'teaser' => $compiled['teaser']?->toArray()];
        });

        return ['body' => CompiledText::fromArray($cached['body']), 'teaser' => null === $cached['teaser'] ? null : CompiledText::fromArray($cached['teaser'])];
    }

    /**
     * @return array{body: CompiledText, teaser: ?CompiledText}
     */
    private function compile(Content $content, string $profile): array
    {
        $parts = preg_split(self::MORE_MARKER, $content->body, 2);
        $parts = false === $parts ? [$content->body] : $parts;

        return [
            'body' => $this->formatter->compile(implode('', $parts), $content->bodyFormat, $profile, $content),
            'teaser' => 2 === \count($parts) ? $this->formatter->compile($parts[0], $content->bodyFormat, $profile, $content) : null,
        ];
    }
}
