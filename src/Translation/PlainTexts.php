<?php

declare(strict_types=1);

namespace Quire\Translation;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a language artifact's file as texts and nothing else: YAML without tags (no PHP constants, enums or
 * objects, which Symfony's own loader would resolve), string keys and texts, nested maps read as "a.b" keys,
 * and at most {@see self::MAX_BYTES}.
 *
 * @internal
 */
final class PlainTexts
{
    public const int MAX_BYTES = 1_048_576;

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException Why the file isn't plain texts
     */
    public static function read(string $file): array
    {
        if (is_link($file) || !is_file($file)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a file.', basename($file)));
        }

        $size = filesize($file);

        if (false === $size || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf('"%s" is larger than %d bytes.', basename($file), self::MAX_BYTES));
        }

        try {
            // No flags: a tag ("!php/const", "!php/enum", "!php/object", custom ones) is an error, not a value
            $texts = Yaml::parse((string) file_get_contents($file));
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t plain texts: %s', basename($file), $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (null === $texts) {
            return [];
        }

        if (!\is_array($texts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t plain texts: it isn\'t a map of texts.', basename($file)));
        }

        return self::flatten($texts, '', basename($file));
    }

    /**
     * @param array<mixed> $texts
     *
     * @return array<string, string>
     */
    private static function flatten(array $texts, string $prefix, string $file): array
    {
        $flat = [];

        foreach ($texts as $key => $text) {
            $key = $prefix.$key;

            if (\is_array($text) && [] !== $text && !array_is_list($text)) {
                $flat += self::flatten($text, $key.'.', $file);
            } elseif (\is_string($text)) {
                $flat[$key] = $text;
            } else {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t plain texts: "%s" is %s, not a text.', $file, $key, get_debug_type($text)));
            }
        }

        return $flat;
    }
}
