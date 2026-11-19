<?php

declare(strict_types=1);

namespace Quire\Formatter;

use Quire\Content\Content;

/**
 * What's being formatted: the profile and format, and the content item when the text belongs to one.
 *
 * @api
 */
final class FormatContext
{
    /**
     * Mark a shortcode placeholder, around the text's mark and the shortcode's number. Private-use
     * characters, removed from the source beforehand. A character reference can still decode to them
     * later (in Markdown, or in its raw HTML), so only a placeholder that also carries the mark counts.
     */
    public const string PLACEHOLDER_START = "\u{E000}";

    public const string PLACEHOLDER_END = "\u{E001}";

    /**
     * The mark of this text's placeholders: random for each compile and kept with the compiled text,
     * never in the output, so no author can write a placeholder.
     */
    public readonly string $mark;

    /** @var list<Shortcode> */
    private array $shortcodes = [];

    /**
     * @param list<string> $shortcodeNames The shortcodes this profile allows and that are registered
     */
    public function __construct(
        public readonly FormatterProfile $profile,
        public readonly string $format,
        public readonly ?Content $content = null,
        private readonly array $shortcodeNames = [],
        ?string $mark = null,
    ) {
        $this->mark = $mark ?? bin2hex(random_bytes(16));
    }

    /**
     * @internal The pattern of the placeholders with this mark, the shortcode's number as group 1; for a
     * regular expression delimited by "/" or "#"
     */
    public static function placeholderPattern(string $mark): string
    {
        return self::PLACEHOLDER_START.preg_quote($mark, '#').'(\\d+)'.self::PLACEHOLDER_END;
    }

    /**
     * Records a shortcode found in the text and returns the placeholder to put in the HTML, or null
     * when the profile doesn't allow it or no extension provides it: leave the text as written then.
     */
    public function placeholder(Shortcode $shortcode): ?string
    {
        if (!\in_array($shortcode->name, $this->shortcodeNames, true)) {
            return null;
        }

        $this->shortcodes[] = $shortcode;

        return self::PLACEHOLDER_START.$this->mark.(\count($this->shortcodes) - 1).self::PLACEHOLDER_END;
    }

    /**
     * @internal
     *
     * @return list<Shortcode>
     */
    public function shortcodes(): array
    {
        return $this->shortcodes;
    }
}
