<?php

declare(strict_types=1);

namespace Quire\Tests\Csp\Fixtures;

use Quire\Formatter\EmbedGuardInterface;

/**
 * An extension's guard of embeds that lets everything through: only quire/privacy fills the slot.
 */
final class OutsideGuard implements EmbedGuardInterface
{
    public function guard(string $html): string
    {
        return $html;
    }
}
