<?php

declare(strict_types=1);

namespace Quire\Formatter\Format;

use Quire\Formatter\FormatContext;
use Quire\Formatter\FormatInterface;
use Quire\Formatter\Internal\ShortcodeSyntax;

/**
 * HTML written by the author. It's sanitised like every format's output, with the profile's policy.
 */
final class HtmlFormat implements FormatInterface
{
    public function toHtml(string $source, FormatContext $context): string
    {
        return ShortcodeSyntax::replace($source, $context);
    }
}
