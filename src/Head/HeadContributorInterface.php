<?php

declare(strict_types=1);

namespace Quire\Head;

/**
 * Adds or changes tags of a page's <head>, registered with {@see \Quire\Extend\Head::contributor()}.
 *
 *     final readonly class VerificationTags implements HeadContributorInterface
 *     {
 *         public function contribute(Head $head, array $context): void
 *         {
 *             $head->meta('google-site-verification', $this->settings->getString('acme.google_token'));
 *         }
 *     }
 *
 * @api
 */
interface HeadContributorInterface
{
    /**
     * @param array<string, mixed> $context The variables of the template that called quire_head(),
     *                                      e.g. "content" on an item's page, "canonical_url", "is_home"
     */
    public function contribute(Head $head, array $context): void;
}
