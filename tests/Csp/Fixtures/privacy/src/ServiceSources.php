<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal;

use Quire\Csp\ThirdPartySourcesInterface;

/**
 * Stands in for quire/privacy's sources (tests/Csp/Fixtures/privacy is the package's place).
 */
final readonly class ServiceSources implements ThirdPartySourcesInterface
{
    public function __construct(private ?ThirdPartySourcesInterface $inner = null)
    {
    }

    public function sources(): array
    {
        return ['script-src' => ['https://www.googletagmanager.com'], ...($this->inner?->sources() ?? [])];
    }
}
