<?php

declare(strict_types=1);

namespace Quire\Permission;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decides abilities case by case, beyond what roles grant: e.g. "organisers may edit the news of their own
 * tournaments", or "suspended commenters may not comment". Register it with Extend\Permissions::rule().
 *
 * Any rule can refuse an ability, whatever roles and other rules say: a refusal wins.
 *
 * @api
 */
interface AbilityRuleInterface
{
    /**
     * @param UserInterface|null $user    Null for visitors who aren't signed in
     * @param mixed              $subject What the ability is checked for, e.g. a content item; null for none
     *
     * @return bool|null True to grant, false to refuse, null to leave it to roles and other rules
     */
    public function decide(string $ability, ?UserInterface $user, mixed $subject): ?bool;
}
