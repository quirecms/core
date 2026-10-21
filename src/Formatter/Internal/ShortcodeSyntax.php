<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Formatter\FormatContext;
use Quire\Formatter\Shortcode;

/**
 * The shortcode syntax: `[[name key=value key="quoted value" key='quoted' flag]]`.
 *
 * @internal
 */
final class ShortcodeSyntax
{
    /** Without delimiters, so CommonMark can combine it with its own patterns. */
    public const string PATTERN = '\[\[[a-z][a-z0-9_-]*(?:\s+[a-z][a-z0-9_-]*(?:=(?:"[^"\n]*"|\'[^\'\n]*\'|[^\s\]"\']+))?)*\s*\]\]';

    private const string ATTRIBUTE_PATTERN = '/([a-z][a-z0-9_-]*)(?:=(?:"([^"\n]*)"|\'([^\'\n]*)\'|([^\s\]"\']+)))?/';

    public static function parse(string $source): Shortcode
    {
        $inner = trim(substr($source, 2, -2));
        $name = (string) preg_replace('/\s.*$/s', '', $inner);
        preg_match_all(self::ATTRIBUTE_PATTERN, substr($inner, \strlen($name)), $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $attributes = [];

        foreach ($matches as $match) {
            $attributes[$match[1]] = $match[2] ?? $match[3] ?? $match[4] ?? true;
        }

        return new Shortcode($name, $attributes, $source);
    }

    /**
     * Replaces each shortcode in plain text with its placeholder, for formats without their own parser.
     */
    public static function replace(string $text, FormatContext $context): string
    {
        return (string) preg_replace_callback(
            '/'.self::PATTERN.'/',
            static fn (array $match): string => $context->placeholder(self::parse($match[0])) ?? $match[0],
            $text,
        );
    }
}
