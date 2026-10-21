<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * Changes the source text before it's parsed, e.g. to expand abbreviations.
 * Register it with {@see \Quire\Extend\Formatter::preProcessor()}.
 *
 * @api
 */
interface SourceProcessorInterface
{
    public function process(string $source, FormatContext $context): string;
}
