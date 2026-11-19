<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Formatter\FormatContext;

/**
 * The words a reader reads in rendered HTML, without what the renderer adds around them
 * ({@see DocumentStructure}): the "#" links beside headings, footnotes and their numbers, the words only
 * screen readers get, a blocked image's explanation, and shortcode placeholders. One reading for every
 * summary of a text: headings' names and lists, excerpts, meta descriptions.
 *
 * @internal
 */
final class ReaderText
{
    /** What the renderer adds, which isn't the author's text */
    private const string ADDED = 'a.heading-anchor, span.footnote, sup:has(> a[href^="#fn-"]), .visually-hidden, .ext-image__why';

    public static function of(\Dom\Element $element): string
    {
        $copy = $element->cloneNode(true);
        \assert($copy instanceof \Dom\Element);

        foreach ($copy->querySelectorAll(self::ADDED) as $added) {
            $added->remove();
        }

        return self::clean((string) $copy->textContent);
    }

    public static function ofHtml(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');

        return $document->body instanceof \Dom\HTMLElement ? self::of($document->body) : '';
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/'.FormatContext::PLACEHOLDER_START.'[0-9a-f]*\d+'.FormatContext::PLACEHOLDER_END.'|['.FormatContext::PLACEHOLDER_START.FormatContext::PLACEHOLDER_END.']/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
