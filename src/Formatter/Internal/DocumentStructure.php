<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Formatter\FormatContext;
use Quire\Formatter\FormatterProfile;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What every theme gets from the renderer for rich text, after the sanitiser has run: the structure
 * screen readers and small screens need, the same for every theme. A theme styles it and decides
 * nothing (docs/themes.md, "What the renderer prints").
 *
 * Nothing of it comes from the author's markup. The author's roles are dropped, the author's ids are
 * put in a namespace of their own ("u-…", with their "#…" links), so none can be a heading's, a
 * footnote's or an id a script looks up; and only the ids the renderer marked itself
 * ($this->mark->value, which no source can carry) become headings' and footnotes'.
 *
 * - **Headings** (with the profile's heading anchors): `<div class="heading-row">` holds the heading,
 *   whose id is prefixed (`h-…`, so it never meets a theme's own ids), and a `<a class="heading-anchor">`
 *   beside it, named "Link to the section …", outside the heading so its name stays the heading's.
 * - **Footnotes** (with the profile's footnotes): each note is put right after its first reference, as
 *   `<span class="footnote" id="fn-N" role="note">`, and the reference is a link named "Note N". On wide
 *   screens a theme sets it in the margin, on narrow ones it reads in place: no list at the end.
 * - **Task lists**: each box is disabled and named by its state ("Done", "Still to come"), and its
 *   item has `data-task="done"` or `"open"`, and the state's words in `data-state`.
 * - **Tables**: in `<div class="table" role="region" tabindex="0">`, named by the heading before them,
 *   so a wide one scrolls by keyboard. The table roles stay when CSS changes its display; every cell
 *   has `data-label` (its column's header), the first cell of a body row is a row header, a column's
 *   cells say what it holds (`data-kind`: "num" for numbers, "pair" for pairs such as "2–1"), and the
 *   table has `data-columns`. A theme decides a layout from these, never from the table's words.
 * - **Code blocks**: `<pre role="region" tabindex="0">`, named "Code" (or "Code: php").
 * - **Images from other sites the page's policy blocks** (ExternalImages): instead of a broken picture,
 *   `<figure class="ext-image">` (an image alone in its paragraph) or `<span class="ext-image">` (in
 *   text), with its description, why, and a link to it on the other site.
 *
 * The texts are in the content's own language (its item is known when it's compiled, and compiled
 * text is kept per item version), or the one it's rendered in.
 *
 * @internal
 */
final readonly class DocumentStructure
{
    /** CommonMark's heading ids get it, and a dash: "h-getting-started" */
    public const string HEADING_PREFIX = 'h';

    public const string FOOTNOTE_PREFIX = 'fn-';

    public const string REFERENCE_PREFIX = 'fnref-';

    /** The author's own ids, out of the way of the renderer's, the theme's and scripts' */
    public const string AUTHOR_PREFIX = 'u-';

    private const string DOMAIN = 'quire';

    public function __construct(
        private TranslatorInterface $translator,
        private ?ExternalImages $images = null,
        private StructureMark $mark = new StructureMark(),
    ) {
    }

    /**
     * @return array{html: string, headings: list<array{id: string, text: string, level: int}>} The HTML, and the
     *                                                                                          headings it gave
     *                                                                                          anchors, in order
     */
    public function apply(string $html, FormatContext $context): array
    {
        if (FormatterProfile::RICH !== $context->profile->sanitizer || '' === trim($html)) {
            return ['html' => $html, 'headings' => []];
        }

        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR, 'UTF-8');
        $body = $document->body;

        if (!$body instanceof \Dom\HTMLElement) {
            return ['html' => $html, 'headings' => []];
        }

        // The item's language; without an item, the one being rendered in
        $locale = $context->content->locale ?? $this->translator->getLocale();
        $this->isolate($body);

        if ($context->profile->footnotes) {
            $this->footnotes($document, $body, $locale);
        }
        $headings = $context->profile->headingAnchors ? $this->headings($document, $body, $locale) : [];
        $this->unmark($body);
        $this->tables($document, $body, $locale);
        $this->codeBlocks($body, $locale);
        $this->taskItems($body, $locale);
        $this->externalImages($document, $body, $locale);

        return ['html' => $body->innerHTML, 'headings' => $headings];
    }

    /**
     * The author's roles go, and the author's ids (and the "#…" links to them) get a namespace: only
     * what the renderer marked keeps its id, for the steps after this one.
     */
    private function isolate(\Dom\HTMLElement $body): void
    {
        foreach ($body->querySelectorAll('[role]') as $element) {
            $element->removeAttribute('role');
        }
        foreach ($body->querySelectorAll('[id]') as $element) {
            $id = (string) $element->getAttribute('id');

            if (!str_starts_with($id, $this->mark->value)) {
                $element->setAttribute('id', self::AUTHOR_PREFIX.$id);
            }
        }
        foreach ($body->querySelectorAll('a[href^="#"]') as $link) {
            $target = substr((string) $link->getAttribute('href'), 1);

            if ('' !== $target && !str_starts_with($target, $this->mark->value)) {
                $link->setAttribute('href', '#'.self::AUTHOR_PREFIX.$target);
            }
        }
    }

    /** Whatever marked id or link is left (a heading without anchors) loses its mark */
    private function unmark(\Dom\HTMLElement $body): void
    {
        foreach ($body->querySelectorAll('[id]') as $element) {
            $element->setAttribute('id', str_replace($this->mark->value, '', (string) $element->getAttribute('id')));
        }
        foreach ($body->querySelectorAll('a[href^="#"]') as $link) {
            $link->setAttribute('href', str_replace($this->mark->value, '', (string) $link->getAttribute('href')));
        }
    }

    /**
     * @return list<array{id: string, text: string, level: int}>
     */
    private function headings(\Dom\HTMLDocument $document, \Dom\HTMLElement $body, ?string $locale): array
    {
        $marked = $this->mark->value.self::HEADING_PREFIX.'-';
        $headings = [];

        foreach ($body->querySelectorAll('h2[id], h3[id], h4[id], h5[id], h6[id]') as $heading) {
            $id = (string) $heading->getAttribute('id');

            if (!str_starts_with($id, $marked) || !$heading->parentNode instanceof \Dom\Node) {
                continue;
            }
            $id = self::HEADING_PREFIX.'-'.substr($id, \strlen($marked));
            $heading->setAttribute('id', $id);
            // Its name as a reader reads it: without a footnote's number or "Note" for screen readers
            $text = ReaderText::of($heading);
            $headings[] = ['id' => $id, 'text' => $text, 'level' => (int) substr(strtolower($heading->tagName), 1)];

            $row = $document->createElement('div');
            $row->setAttribute('class', 'heading-row');
            $heading->parentNode?->insertBefore($row, $heading);
            $row->appendChild($heading);

            $anchor = $document->createElement('a');
            $anchor->setAttribute('class', 'heading-anchor');
            $anchor->setAttribute('href', '#'.$id);
            $anchor->setAttribute('aria-label', $this->translator->trans('Link to the section “{title}”', ['title' => $text], self::DOMAIN, $locale));
            $anchor->textContent = '#';
            $row->appendChild($anchor);
        }

        return $headings;
    }

    /**
     * CommonMark's footnotes come as references (`<sup id="⟨mark⟩fnref-N"><a href="#⟨mark⟩fn-N">`)
     * and a list at the end (items `<li id="⟨mark⟩fn-N">`): each note moves next to its first reference.
     */
    private function footnotes(\Dom\HTMLDocument $document, \Dom\HTMLElement $body, ?string $locale): void
    {
        $noteMark = $this->mark->value.self::FOOTNOTE_PREFIX;
        $notes = [];
        $lists = [];

        foreach ($body->querySelectorAll('li[id]') as $item) {
            $id = (string) $item->getAttribute('id');

            if (str_starts_with($id, $noteMark)) {
                $notes[$id] ??= $item;
                // Its list's box (an extension may add CommonMark's footnotes a second time)
                $box = $item->parentNode?->parentNode;
                if ($box instanceof \Dom\Element && $box !== $body) {
                    $lists[spl_object_id($box)] = $box;
                }
            }
        }

        if ([] === $notes) {
            return;
        }
        $number = 0;
        $placed = [];

        foreach ($body->querySelectorAll('a[href^="#"]') as $reference) {
            $target = substr((string) $reference->getAttribute('href'), 1);
            $note = $notes[$target] ?? null;
            $sup = $reference->parentNode;

            if (!$note instanceof \Dom\Node || !$sup instanceof \Dom\Element || 'sup' !== strtolower($sup->tagName) || !$sup->parentNode instanceof \Dom\Node) {
                continue;
            }
            $plain = self::FOOTNOTE_PREFIX.substr($target, \strlen($noteMark));
            $shown = $placed[$target] ??= ++$number;
            $label = $this->translator->trans('Note {number}', ['number' => $shown], self::DOMAIN, $locale);
            $reference->setAttribute('href', '#'.$plain);
            $reference->setAttribute('aria-label', $label);
            $reference->textContent = (string) $shown;
            $sup->removeAttribute('id');

            if ($shown !== $number) {
                continue;
            }
            $reference->setAttribute('id', self::REFERENCE_PREFIX.$shown);

            $side = $document->createElement('span');
            $side->setAttribute('class', 'footnote');
            $side->setAttribute('id', $plain);
            $side->setAttribute('role', 'note');
            $mark = $document->createElement('b');
            $hidden = $document->createElement('span');
            $hidden->setAttribute('class', 'visually-hidden');
            $hidden->textContent = (string) preg_replace('/\s*\d+\s*$/u', ' ', $label);
            $mark->appendChild($hidden);
            $mark->appendChild($document->createTextNode((string) $shown));
            $side->appendChild($mark);
            $side->appendChild($document->createTextNode(' '));

            // A note's back-links (to the marked references) aren't needed beside the text
            foreach ($note->querySelectorAll('a[href^="#"]') as $back) {
                if (!str_starts_with(substr((string) $back->getAttribute('href'), 1), $this->mark->value.self::REFERENCE_PREFIX)) {
                    continue;
                }
                // With the space CommonMark puts before it
                $before = $back->previousSibling;

                if ($before instanceof \Dom\Text) {
                    // By characters, not bytes: "voilà" ends in the byte a no-break space ends in
                    $before->data = (string) preg_replace('/[\s\x{00A0}]+$/u', '', $before->data);
                }
                $back->remove();
            }

            // Its blocks (paragraphs) read as one line beside the text
            $first = true;

            foreach (iterator_to_array($note->childNodes) as $child) {
                if ($child instanceof \Dom\Element && \in_array(strtolower($child->tagName), ['p', 'div'], true)) {
                    if (!$first) {
                        $side->appendChild($document->createTextNode(' '));
                    }
                    foreach (iterator_to_array($child->childNodes) as $inline) {
                        $side->appendChild($inline);
                    }
                    $first = false;
                } elseif ($child instanceof \Dom\Element) {
                    $side->appendChild($document->createTextNode(trim((string) $child->textContent).' '));
                } else {
                    $side->appendChild($child);
                }
            }
            $sup->parentNode?->insertBefore($side, $sup->nextSibling);
        }

        foreach ($lists as $list) {
            $list->remove();
        }
    }

    private function tables(\Dom\HTMLDocument $document, \Dom\HTMLElement $body, ?string $locale): void
    {
        $count = 0;

        foreach ($body->querySelectorAll('table') as $table) {
            if (!$table->parentNode instanceof \Dom\Node) {
                continue;
            }
            ++$count;
            $headers = [];

            foreach ($table->querySelectorAll('thead th') as $header) {
                $headers[] = trim((string) $header->textContent);
                $header->setAttribute('role', 'columnheader');
                $header->setAttribute('scope', 'col');
            }
            $rows = $table->querySelectorAll('tbody tr');
            $columns = [];

            foreach ($rows as $row) {
                $row->setAttribute('role', 'row');
                $index = 0;

                foreach (iterator_to_array($row->children) as $cell) {
                    $text = trim((string) $cell->textContent);
                    $columns[$index][] = $text;

                    if (0 === $index && 'td' === strtolower($cell->tagName) && \count($headers) > 1) {
                        $header = $document->createElement('th');
                        foreach ($cell->getAttributeNames() as $name) {
                            if (\is_string($name)) {
                                $header->setAttribute($name, (string) $cell->getAttribute($name));
                            }
                        }
                        foreach (iterator_to_array($cell->childNodes) as $child) {
                            $header->appendChild($child);
                        }
                        $row->replaceChild($header, $cell);
                        $cell = $header;
                        $cell->setAttribute('scope', 'row');
                        $cell->setAttribute('role', 'rowheader');
                    } else {
                        $cell->setAttribute('role', 'cell');
                    }
                    if (isset($headers[$index]) && '' !== $headers[$index]) {
                        $cell->setAttribute('data-label', $headers[$index]);
                    }
                    ++$index;
                }
            }

            foreach ($columns as $index => $values) {
                $kind = $this->kind($values);

                if (null === $kind) {
                    continue;
                }
                foreach ([...$table->querySelectorAll('thead tr'), ...$rows] as $row) {
                    $cell = $row->children->item($index);

                    if (null !== $cell) {
                        $cell->setAttribute('data-kind', $kind);
                    }
                }
            }

            $table->setAttribute('role', 'table');
            $table->setAttribute('data-columns', (string) max(\count($headers), \count($columns)));
            foreach ($table->querySelectorAll('thead, tbody') as $group) {
                $group->setAttribute('role', 'rowgroup');
            }
            foreach ($table->querySelectorAll('thead tr') as $row) {
                $row->setAttribute('role', 'row');
            }

            $region = $document->createElement('div');
            $region->setAttribute('class', 'table');
            $region->setAttribute('role', 'region');
            $region->setAttribute('tabindex', '0');
            $heading = $this->headingBefore($table);

            if (null !== $heading) {
                $region->setAttribute('aria-labelledby', $heading);
            } else {
                $region->setAttribute('aria-label', $this->translator->trans('Table {number}', ['number' => $count], self::DOMAIN, $locale));
            }
            $table->parentNode?->insertBefore($region, $table);
            $region->appendChild($table);
        }
    }

    /**
     * A task list's boxes ("- [x] Done", "- [ ] Not yet") are a state, not a control: disabled, named by
     * their state in the item's language ("Done", "Still to come"), and their item says it with data-task
     * (done, open), so a theme can draw the state without guessing.
     */
    private function taskItems(\Dom\HTMLElement $body, ?string $locale): void
    {
        foreach ($body->querySelectorAll('input[type="checkbox"]') as $box) {
            $done = $box->hasAttribute('checked');
            $state = $done ? $this->translator->trans('Done', [], self::DOMAIN, $locale) : $this->translator->trans('Still to come', [], self::DOMAIN, $locale);
            $box->setAttribute('disabled', '');
            $box->setAttribute('aria-label', $state);

            if ($box->parentElement instanceof \Dom\Element && 'li' === strtolower($box->parentElement->tagName)) {
                $box->parentElement->setAttribute('data-task', $done ? 'done' : 'open');
                // For a theme to write the state beside the box: an attribute, so feeds and excerpts never carry it
                $box->parentElement->setAttribute('data-state', $state);
            }
        }
    }

    private function codeBlocks(\Dom\HTMLElement $body, ?string $locale): void
    {
        foreach ($body->querySelectorAll('pre') as $pre) {
            $language = null;
            $code = $pre->querySelector('code');

            if (null !== $code && 1 === preg_match('/(?:^|\s)language-([\w+#.-]{1,30})(?:\s|$)/', (string) $code->getAttribute('class'), $match)) {
                $language = $match[1];
            }
            $pre->setAttribute('role', 'region');
            $pre->setAttribute('tabindex', '0');
            $pre->setAttribute('aria-label', null === $language
                ? $this->translator->trans('Code', [], self::DOMAIN, $locale)
                : $this->translator->trans('Code: {language}', ['language' => $language], self::DOMAIN, $locale));
        }
    }

    private function externalImages(\Dom\HTMLDocument $document, \Dom\HTMLElement $body, ?string $locale): void
    {
        if (!$this->images instanceof ExternalImages) {
            return;
        }

        foreach ($body->querySelectorAll('img[src]') as $image) {
            $url = (string) $image->getAttribute('src');
            $host = $this->images->blockedHost($url);
            $parent = $image->parentNode;

            if (null === $host || !$parent instanceof \Dom\Element) {
                continue;
            }
            $alone = 'p' === strtolower($parent->tagName) && '' === trim(str_replace((string) $image->textContent, '', (string) $parent->textContent)) && 1 === $parent->childElementCount;
            $part = $alone ? 'p' : 'span';
            $box = $document->createElement($alone ? 'figure' : 'span');
            $box->setAttribute('class', 'ext-image');
            $icon = $document->createElement('span');
            $icon->setAttribute('class', 'ext-image__icon');
            $icon->setAttribute('aria-hidden', 'true');
            $box->appendChild($icon);

            $description = (string) $image->getAttribute('alt');
            if ('' !== trim($description)) {
                $alt = $document->createElement($part);
                $alt->setAttribute('class', 'ext-image__alt');
                $alt->textContent = $description;
                $box->appendChild($alt);
                $box->appendChild($document->createTextNode(' '));
            }
            $why = $document->createElement($part);
            $why->setAttribute('class', 'ext-image__why');
            $why->textContent = $this->translator->trans('This image is on {host}, and this site shows only its own images.', ['host' => $host], self::DOMAIN, $locale);
            $box->appendChild($why);
            $box->appendChild($document->createTextNode(' '));

            $line = $document->createElement($part);
            $link = $document->createElement('a');
            $link->setAttribute('href', $url);
            $link->setAttribute('rel', 'noopener noreferrer');
            $link->textContent = $this->translator->trans('View the image on {host}', ['host' => $host], self::DOMAIN, $locale);
            $line->appendChild($link);
            $box->appendChild($line);

            if ($alone) {
                $parent->parentNode?->replaceChild($box, $parent);
            } else {
                $parent->replaceChild($box, $image);
            }
        }
    }

    /**
     * What a column holds, when every cell is the same kind: numbers ("4,200", "−3", "12%") or pairs
     * of numbers ("2–1", "3-2"), or null for text and mixed columns. Empty cells don't count.
     *
     * @param list<string> $values
     */
    private function kind(array $values): ?string
    {
        $values = array_values(array_filter($values, static fn (string $value): bool => '' !== $value));

        if ([] === $values) {
            return null;
        }
        $number = '[+−-]?\d[\d.,\x{202F}\x{00A0} ]*%?';

        foreach (['pair' => '/^\d+\s*[–—-]\s*\d+$/u', 'num' => '/^'.$number.'$/u'] as $kind => $pattern) {
            $matching = preg_grep($pattern, $values);

            if (\is_array($matching) && \count($values) === \count($matching)) {
                return $kind;
            }
        }

        return null;
    }

    /** The id of the closest heading before an element, among its earlier siblings */
    private function headingBefore(\Dom\Element $element): ?string
    {
        for ($node = $element->previousElementSibling; $node instanceof \Dom\Element; $node = $node->previousElementSibling) {
            $heading = 'div' === strtolower($node->tagName) && 'heading-row' === $node->getAttribute('class') ? $node->firstElementChild : $node;

            if ($heading instanceof \Dom\Element && 1 === preg_match('/^h[2-6]$/i', $heading->tagName) && $heading->hasAttribute('id')) {
                return (string) $heading->getAttribute('id');
            }
        }

        return null;
    }
}
