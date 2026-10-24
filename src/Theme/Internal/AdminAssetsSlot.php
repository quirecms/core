<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Theme\SlotRendererInterface;

/**
 * Adds the stylesheets and scripts of the group "admin" (see Extend\Assets) to the slot "admin.head",
 * which admin interfaces render in the <head> of their pages.
 *
 * @internal
 */
final readonly class AdminAssetsSlot implements SlotRendererInterface
{
    public function __construct(
        private AssetsRenderer $assets,
    ) {
    }

    public function render(array $context): string
    {
        return $this->assets->render('admin');
    }
}
