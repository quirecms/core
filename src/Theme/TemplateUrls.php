<?php

declare(strict_types=1);

namespace Quire\Theme;

/**
 * The absolute URLs a template loads, as written in it (not those made in Twig), with the directive
 * of the Content-Security-Policy that decides about each: for checks of what a theme loads from other
 * sites. Links (<a href>) load nothing.
 *
 * @api
 */
final class TemplateUrls
{
    /** <link rel="…"> that make the browser fetch or connect to the URL, and what decides about it */
    private const array LINK_RELS = [
        'stylesheet' => 'style-src',
        'preload' => 'default-src',
        'modulepreload' => 'script-src',
        'prefetch' => 'default-src',
        'icon' => 'img-src',
        'apple-touch-icon' => 'img-src',
        'manifest' => 'manifest-src',
        // A connection alone gives the other site the visitor's address.
        'preconnect' => 'default-src',
        'dns-prefetch' => 'default-src',
    ];

    private const array ELEMENTS = [
        'script' => 'script-src',
        'iframe' => 'frame-src',
        'frame' => 'frame-src',
        'img' => 'img-src',
        'image' => 'img-src',
        'picture' => 'img-src',
        'video' => 'media-src',
        'audio' => 'media-src',
        'track' => 'media-src',
        'object' => 'object-src',
        'embed' => 'object-src',
        'input' => 'img-src',
    ];

    private const array ATTRIBUTES = ['src', 'href', 'poster', 'data', 'srcset', 'imagesrcset'];

    /**
     * @return list<array{url: string, element: string, directive: string, line: int}> Protocol-relative
     *                                                                                 URLs as https:
     */
    public static function find(string $source): array
    {
        $found = [];

        if (false === preg_match_all('~<(?<element>[a-zA-Z][a-zA-Z0-9-]*)\b(?<attributes>(?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~', $source, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($tags as $tag) {
            $element = strtolower($tag['element'][0]);
            $attributes = self::attributes($tag['attributes'][0]);
            $line = substr_count($source, "\n", 0, $tag[0][1]) + 1;
            $directive = self::ELEMENTS[$element] ?? null;

            if ('source' === $element) {
                // A picture's sources are images, a video's or an audio's are media.
                $start = max(0, $tag[0][1] - 2000);
                $before = strtolower(substr($source, $start, $tag[0][1] - $start));
                $directive = strrpos($before, '<picture') > max((int) strrpos($before, '<video'), (int) strrpos($before, '<audio')) ? 'img-src' : 'media-src';
            }

            if ('link' === $element) {
                $rels = preg_split('/\s+/', strtolower($attributes['rel'] ?? ''));
                $directive = null;

                foreach (false === $rels ? [] : $rels as $rel) {
                    if (isset(self::LINK_RELS[$rel])) {
                        $directive = 'preload' === $rel ? self::preloadDirective($attributes['as'] ?? '') : self::LINK_RELS[$rel];

                        break;
                    }
                }
            }

            if (null === $directive) {
                continue;
            }

            foreach (self::ATTRIBUTES as $attribute) {
                $value = $attributes[$attribute] ?? null;

                if (null === $value || ('href' === $attribute && 'link' !== $element)) {
                    continue;
                }

                $urls = str_ends_with($attribute, 'srcset') ? array_map(static fn (string $candidate): string => preg_split('/\s+/', trim($candidate))[0] ?? '', explode(',', $value)) : [$value];

                foreach ($urls as $url) {
                    $url = trim($url);

                    if (str_starts_with($url, '//')) {
                        $url = 'https:'.$url;
                    }

                    // Literal URLs only: one made in Twig isn't known here.
                    if (1 === preg_match('~^(?:https?|wss?)://~i', $url) && !str_contains($url, '{{') && !str_contains($url, '{%')) {
                        $found[] = ['url' => $url, 'element' => $element, 'directive' => $directive, 'line' => $line];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(string $text): array
    {
        preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~', $text, $matches, \PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= html_entity_decode(($match[2] ?? '').($match[3] ?? '').($match[4] ?? ''), \ENT_QUOTES | \ENT_HTML5);
        }

        return $attributes;
    }

    private static function preloadDirective(string $as): string
    {
        return match (strtolower($as)) {
            'script' => 'script-src',
            'style' => 'style-src',
            'font' => 'font-src',
            'image' => 'img-src',
            'audio', 'video', 'track' => 'media-src',
            'fetch' => 'connect-src',
            default => 'default-src',
        };
    }
}
