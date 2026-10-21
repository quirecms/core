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
     * @param list<Shortcode> $shortcodes Indexed by placeholder number
     */
    public function __construct(
        public string $html,
        public array $shortcodes,
        public string $profile,
        public string $format,
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
     * @return array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string}
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'shortcodes' => array_map(static fn (Shortcode $s): array => ['name' => $s->name, 'attributes' => $s->attributes, 'source' => $s->source], $this->shortcodes),
            'profile' => $this->profile,
            'format' => $this->format,
        ];
    }

    /**
     * @internal
     *
     * @param array{html: string, shortcodes: list<array{name: string, attributes: array<string, string|true>, source: string}>, profile: string, format: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['html'],
            array_map(static fn (array $s): Shortcode => new Shortcode($s['name'], $s['attributes'], $s['source']), $data['shortcodes']),
            $data['profile'],
            $data['format'],
        );
    }
}
