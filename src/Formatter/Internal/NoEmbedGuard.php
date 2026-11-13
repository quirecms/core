<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Formatter\EmbedGuardInterface;

/**
 * Without `quire/privacy`: shortcodes' output as it is.
 *
 * @internal
 */
final class NoEmbedGuard implements EmbedGuardInterface
{
    public function guard(string $html): string
    {
        return $html;
    }
}
