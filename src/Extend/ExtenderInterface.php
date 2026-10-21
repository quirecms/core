<?php

declare(strict_types=1);

namespace Quire\Extend;

/**
 * Registers behaviour with Quire.
 *
 * An extension's `extend.php` returns a list of extenders. Quire runs them while it builds the service
 * container, in boot order, so their effects are compiled and cached: they cost nothing per request.
 *
 * @api
 */
interface ExtenderInterface
{
    /**
     * @throws ExtenderException when the extender is misconfigured
     */
    public function extend(ExtenderContext $context): void;
}
