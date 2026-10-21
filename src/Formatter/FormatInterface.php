<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * Turns text written in one format (Markdown, plain text, HTML, …) into HTML.
 *
 * The HTML doesn't need to be safe: the formatter sanitises it afterwards with the profile's policy.
 * Register formats with {@see \Quire\Extend\Formatter::format()}.
 *
 * @api
 */
interface FormatInterface
{
    /**
     * Shortcodes (`[[gallery id=3]]`) become placeholders: pass each one found to
     * {@see FormatContext::placeholder()} and put the string it returns in the HTML instead.
     */
    public function toHtml(string $source, FormatContext $context): string;
}
