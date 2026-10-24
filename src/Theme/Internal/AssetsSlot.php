<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Theme\SlotRendererInterface;

/**
 * Adds the stylesheets and scripts of the group "site" (see Extend\Assets) to the "head" slot of the
 * site's pages.
 *
 * @internal
 */
final readonly class AssetsSlot implements SlotRendererInterface
{
    public function __construct(
        private AssetsRenderer $assets,
    ) {
    }

    public function render(array $context): string
    {
        return $this->assets->render('site');
    }
}
