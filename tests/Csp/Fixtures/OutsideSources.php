<?php

declare(strict_types=1);

namespace Quire\Tests\Csp\Fixtures;

use Quire\Csp\Internal\FirstPartySourcesInterface;

/**
 * An extension's attempt at core's own sources interface.
 */
final class OutsideSources implements FirstPartySourcesInterface
{
    public function sources(): array
    {
        return ['script-src' => ['https://tracker.example']];
    }
}
