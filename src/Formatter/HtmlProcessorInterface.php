<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * Changes the HTML after parsing and before it's sanitised, e.g. to add lazy loading to images.
 * Register it with {@see \Quire\Extend\Formatter::postProcessor()}.
 *
 * @api
 */
interface HtmlProcessorInterface
{
    public function process(string $html, FormatContext $context): string;
}
