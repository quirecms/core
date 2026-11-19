<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * Formatted, sanitised HTML with placeholders where its shortcodes go. This is what's cached; the
 * shortcodes are rendered each time with {@see Formatter::expand()}.
 *
 * @api
 */
final readonly class CompiledText
{
    /**
     * @param list<Shortcode>                                   $shortcodes Indexed by placeholder number
     * @param list<array{id: string, text: string, level: int}> $headings   The headings the renderer gave anchors, in
     *                                                                      order, named as a reader reads them
     */
    public function __construct(
        public string $html,
        public array $shortcodes,
        public string $profile,
        public string $format,
        public array $headings = [],
    ) {
    }

    /**
     * The HTML without shortcodes, e.g. for search snippets.
     */
    public function htmlWithoutShortcodes(): string
    {
        return (string) preg_replace('/'.FormatContext::PLACEHOLDER_START.'\d+'.FormatContext::PLACEHOLDER_END.'/u', '', $this->html);
    }

    /**
     * @internal For caches
     *
     * @return array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string, headings: list<array{id: string, text: string, level: int}>}
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'shortcodes' => array_map(static fn (Shortcode $s): array => ['name' => $s->name, 'attributes' => $s->attributes, 'source' => $s->source], $this->shortcodes),
            'profile' => $this->profile,
            'format' => $this->format,
            'headings' => $this->headings,
        ];
    }

    /**
     * @internal
     *
     * @param array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string, headings?: list<array{id: string, text: string, level: int}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['html'],
            array_map(static fn (array $s): Shortcode => new Shortcode($s['name'], $s['attributes'], $s['source']), $data['shortcodes']),
            $data['profile'],
            $data['format'],
            $data['headings'] ?? [],
        );
    }
}
