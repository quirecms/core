<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Quire\Theme\SlotRendererInterface;

final class FixtureSlot implements SlotRendererInterface
{
    public function render(array $context): string
    {
        return '<p>Fixture</p>';
    }
}
