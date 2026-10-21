<?php

declare(strict_types=1);

namespace Quire\Formatter\Format;

use Quire\Formatter\FormatContext;
use Quire\Formatter\FormatInterface;
use Quire\Formatter\Internal\ShortcodeSyntax;

/**
 * Plain text: blank lines separate paragraphs, line breaks are kept, nothing else is interpreted.
 */
final class TextFormat implements FormatInterface
{
    public function toHtml(string $source, FormatContext $context): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim(str_replace("\r\n", "\n", $source)));
        $html = '';

        foreach (false === $paragraphs ? [] : $paragraphs as $paragraph) {
            if ('' === trim($paragraph)) {
                continue;
            }

            // Placeholders first: they contain nothing that escaping would change.
            $escaped = htmlspecialchars(ShortcodeSyntax::replace($paragraph, $context), \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
            $html .= '<p>'.nl2br($escaped, false)."</p>\n";
        }

        return $html;
    }
}
