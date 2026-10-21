<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * A named set of formatting rules, so text from different sources gets different features: content
 * written by editors can use headings, images and shortcodes, comments by visitors only basic markup.
 *
 * @api
 */
final readonly class FormatterProfile
{
    /** Headings, tables, images, figures, … everything that's safe, but no scripts, styles, forms or frames. */
    public const string RICH = 'rich';

    /** Paragraphs, emphasis, links, lists, quotes and code. Other elements are reduced to their text. */
    public const string BASIC = 'basic';

    /**
     * @param list<string> $formats    Formats text in this profile may be written in
     * @param list<string> $shortcodes Allowed shortcodes; "*" allows all
     * @param string       $sanitizer  {@see RICH} or {@see BASIC}
     * @param list<string> $linkRel    Added to every link's rel attribute, e.g. ["nofollow", "ugc"]
     */
    public function __construct(
        public string $name,
        public string $owner,
        public array $formats,
        public array $shortcodes = [],
        public string $sanitizer = self::RICH,
        public bool $headingAnchors = false,
        public bool $images = true,
        public bool $rawHtml = false,
        public array $linkRel = [],
    ) {
    }

    public function allowsShortcode(string $name): bool
    {
        return \in_array('*', $this->shortcodes, true) || \in_array($name, $this->shortcodes, true);
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'owner' => $this->owner,
            'formats' => $this->formats,
            'shortcodes' => $this->shortcodes,
            'sanitizer' => $this->sanitizer,
            'heading_anchors' => $this->headingAnchors,
            'images' => $this->images,
            'raw_html' => $this->rawHtml,
            'link_rel' => $this->linkRel,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{name: string, owner: string, formats: list<string>, shortcodes: list<string>, sanitizer: string, heading_anchors: bool, images: bool, raw_html: bool, link_rel: list<string>} $profile */
        $profile = $data;

        return new self(
            name: $profile['name'],
            owner: $profile['owner'],
            formats: $profile['formats'],
            shortcodes: $profile['shortcodes'],
            sanitizer: $profile['sanitizer'],
            headingAnchors: $profile['heading_anchors'],
            images: $profile['images'],
            rawHtml: $profile['raw_html'],
            linkRel: $profile['link_rel'],
        );
    }
}
