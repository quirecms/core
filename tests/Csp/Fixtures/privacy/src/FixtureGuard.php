<?php

declare(strict_types=1);

namespace Quire\Privacy\Fixture;

use Quire\Formatter\EmbedGuardInterface;

/**
 * Stands in for quire/privacy's guard of embeds (tests/Csp/Fixtures/privacy is the package's place).
 */
final readonly class FixtureGuard implements EmbedGuardInterface
{
    public function __construct(private ?EmbedGuardInterface $inner = null)
    {
    }

    public function guard(string $html): string
    {
        return $this->inner?->guard($html) ?? $html;
    }
}
