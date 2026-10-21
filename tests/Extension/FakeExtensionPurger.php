<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use Quire\Extension\ExtensionPurgerInterface;

/**
 * Records which extensions would be purged, in order.
 */
final class FakeExtensionPurger implements ExtensionPurgerInterface
{
    /** @var list<string> */
    public array $purged = [];

    public function purge(array $extensions, \Closure $output): void
    {
        foreach ($extensions as $extension) {
            $this->purged[] = $extension->id;
        }
    }
}
