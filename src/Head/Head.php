<?php

declare(strict_types=1);

namespace Quire\Head;

/**
 * The tags of a page's <head> that `{{ quire_head() }}` prints: meta tags, links and structured data.
 *
 * Every tag has a key, so a page never has two of the same: setting a key again replaces the tag where
 * it was, and null removes it. Head contributors (see {@see \Quire\Extend\Head}) fill it in order of
 * priority, so a later one can change what an earlier one set:
 *
 *     $head->meta('description', 'Hand-made greeting cards');   // key "meta:description"
 *     $head->property('og:image', 'https://example.com/card.png'); // key "property:og:image"
 *     $head->link('canonical', 'https://example.com/cards');      // key "link:canonical"
 *     $head->link('alternate', 'https://example.com/de/karten', ['hreflang' => 'de'], key: 'alternate:de');
 *     $head->jsonLd('website', ['@context' => 'https://schema.org', '@type' => 'WebSite', ...]);
 *
 * The page's <title> isn't here: the layout's `title` block prints it.
 *
 * @api
 */
final class Head
{
    /** @var array<string, array{element: 'meta'|'link', attributes: array<string, string>}|array{element: 'json-ld', data: array<mixed>}> */
    private array $tags = [];

    /**
     * `<meta name="…" content="…">`, key "meta:<name>". Null or "" removes it.
     */
    public function meta(string $name, ?string $content): self
    {
        return $this->set('meta:'.$name, null === $content || '' === $content ? null : ['element' => 'meta', 'attributes' => ['name' => $name, 'content' => $content]]);
    }

    /**
     * `<meta property="…" content="…">`, as Open Graph writes its tags (e.g. "og:title"), key
     * "property:<property>". Null or "" removes it.
     */
    public function property(string $property, ?string $content): self
    {
        return $this->set('property:'.$property, null === $content || '' === $content ? null : ['element' => 'meta', 'attributes' => ['property' => $property, 'content' => $content]]);
    }

    /**
     * `<link rel="…" href="…">`, key "link:<rel>", or "link:<key>" for a rel a page has several of
     * (e.g. one "alternate" per language). Null removes it.
     *
     * @param array<string, string> $attributes More attributes, e.g. ['hreflang' => 'de']
     */
    public function link(string $rel, ?string $href, array $attributes = [], ?string $key = null): self
    {
        return $this->set('link:'.($key ?? $rel), null === $href ? null : ['element' => 'link', 'attributes' => ['rel' => $rel, 'href' => $href, ...$attributes]]);
    }

    /**
     * Structured data (JSON-LD), printed as its own `<script type="application/ld+json">`, key
     * "json-ld:<key>". Null removes it.
     *
     * @param array<mixed>|null $data E.g. a schema.org object with "@context" and "@type"
     */
    public function jsonLd(string $key, ?array $data): self
    {
        return $this->set('json-ld:'.$key, null === $data ? null : ['element' => 'json-ld', 'data' => $data]);
    }

    /**
     * Removes a tag by its key, e.g. "meta:robots".
     */
    public function remove(string $key): self
    {
        return $this->set($key, null);
    }

    public function has(string $key): bool
    {
        return isset($this->tags[$key]);
    }

    /**
     * The tag's attributes (for meta and link tags) or data (for structured data), or null.
     *
     * @return array<mixed>|null
     */
    public function get(string $key): ?array
    {
        $tag = $this->tags[$key] ?? null;

        return match (true) {
            null === $tag => null,
            'json-ld' === $tag['element'] => $tag['data'],
            default => $tag['attributes'],
        };
    }

    /**
     * The keys of the tags, in the order they're printed.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->tags);
    }

    /**
     * The tags as HTML, one per line, with every value escaped.
     */
    public function toHtml(): string
    {
        $lines = [];

        foreach ($this->tags as $tag) {
            if ('json-ld' === $tag['element']) {
                // Escaped so the data can't close the script element.
                $lines[] = '<script type="application/ld+json">'.json_encode($tag['data'], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR).'</script>';

                continue;
            }

            $attributes = '';

            foreach ($tag['attributes'] as $name => $value) {
                $attributes .= \sprintf(' %s="%s"', htmlspecialchars($name), htmlspecialchars($value));
            }

            $lines[] = \sprintf('<%s%s>', $tag['element'], $attributes);
        }

        return [] === $lines ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param array{element: 'meta'|'link', attributes: array<string, string>}|array{element: 'json-ld', data: array<mixed>}|null $tag
     */
    private function set(string $key, ?array $tag): self
    {
        if (null === $tag) {
            unset($this->tags[$key]);
        } else {
            $this->tags[$key] = $tag;
        }

        return $this;
    }
}
