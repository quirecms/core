<?php

declare(strict_types=1);

namespace Quire\Setup;

/**
 * A step of setting a site up, added with {@see \Quire\Extend\SetupSteps}: e.g. "Name your site", or
 * an extension's "Connect your shop". Its state is read from the site each time, never stored.
 *
 *     final readonly class ConnectShopStep implements SetupStepInterface
 *     {
 *         public function step(): ?SetupStep
 *         {
 *             if (!$this->security->isGranted('acme-shop.manage')) {
 *                 return null; // not this person's to take
 *             }
 *
 *             return new SetupStep('acme-shop.connect', 'Connect your shop', 'So orders arrive here.', $this->shop->isConnected(), $this->urls->generate('acme_shop_settings'), 'Connect');
 *         }
 *     }
 *
 * @api
 */
interface SetupStepInterface
{
    /**
     * The step for the person looking, or null when it isn't theirs (they may not take it, or it
     * doesn't apply to this site). Quick, and changes nothing: decide "not this person's" first,
     * before any query, since every step is asked, for visitors too.
     */
    public function step(): ?SetupStep;
}
