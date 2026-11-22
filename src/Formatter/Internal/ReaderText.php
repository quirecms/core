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

    /** Not read as a sentence, so not in a summary: tables, code blocks, an image shown as a link */
    private const string NOT_PROSE = 'div.table, table, pre, figure.ext-image';

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

    /**
     * The words of a summary (an excerpt, a description): the text's prose, without what isn't read as
     * a sentence. Tables, code blocks and an image shown as a link (`figure.ext-image`) are left out; an
     * image within a sentence keeps only its description. Falls back to all the words when the text is
     * nothing but those (a table alone).
     */
    public static function summaryOfHtml(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if (!$body instanceof \Dom\HTMLElement) {
            return '';
        }

        $prose = $body->cloneNode(true);
        \assert($prose instanceof \Dom\Element);

        foreach ($prose->querySelectorAll(self::NOT_PROSE) as $element) {
            $element->remove();
        }

        foreach ($prose->querySelectorAll('span.ext-image') as $image) {
            $description = $image->querySelector('.ext-image__alt');
            $image->replaceWith(null === $description ? '' : (string) $description->textContent);
        }

        $text = self::of($prose);

        return '' !== $text ? $text : self::of($body);
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/'.FormatContext::PLACEHOLDER_START.'[0-9a-f]*\d+'.FormatContext::PLACEHOLDER_END.'|['.FormatContext::PLACEHOLDER_START.FormatContext::PLACEHOLDER_END.']/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
