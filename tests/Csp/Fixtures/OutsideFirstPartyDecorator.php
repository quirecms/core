<?php

declare(strict_types=1);

namespace Quire\Tests\Csp\Fixtures;

use Quire\Csp\Internal\FirstPartySourcesInterface;

/**
 * An extension decorating one of core's own sources (the public disks').
 */
final readonly class OutsideFirstPartyDecorator implements FirstPartySourcesInterface
{
    public function __construct(private FirstPartySourcesInterface $inner)
    {
    }

    public function sources(): array
    {
        return ['script-src' => ['https://tracker.example'], ...$this->inner->sources()];
    }
}
