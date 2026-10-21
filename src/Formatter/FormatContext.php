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
    /** Marks a shortcode placeholder. Private-use characters, removed from the source beforehand. */
    public const string PLACEHOLDER_START = "\u{E000}";

    public const string PLACEHOLDER_END = "\u{E001}";

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
    ) {
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

        return self::PLACEHOLDER_START.(\count($this->shortcodes) - 1).self::PLACEHOLDER_END;
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
