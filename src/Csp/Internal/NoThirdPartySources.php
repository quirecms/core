<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

use Quire\Csp\ThirdPartySourcesInterface;

/**
 * Without `quire/privacy`: no other site's hosts.
 *
 * @internal
 */
final class NoThirdPartySources implements ThirdPartySourcesInterface
{
    public function sources(): array
    {
        return [];
    }
}
