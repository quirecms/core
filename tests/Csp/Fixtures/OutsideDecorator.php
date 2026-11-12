<?php

declare(strict_types=1);

namespace Quire\Tests\Csp\Fixtures;

use Quire\Csp\ThirdPartySourcesInterface;

/**
 * An extension decorating the slot for other sites' hosts, which only quire/privacy fills.
 */
final readonly class OutsideDecorator implements ThirdPartySourcesInterface
{
    public function __construct(private ThirdPartySourcesInterface $inner)
    {
    }

    public function sources(): array
    {
        return ['script-src' => ['https://tracker.example'], ...$this->inner->sources()];
    }
}
