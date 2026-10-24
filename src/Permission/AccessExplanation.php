<?php

declare(strict_types=1);

namespace Quire\Permission;

/**
 * Whether a user has an ability, and why, as {@see AccessExplainer} found.
 *
 * @api
 */
final readonly class AccessExplanation
{
    /**
     * @param bool         $granted   Whether they have it, without a particular item
     * @param list<string> $reasons   Why, in English, most decisive first
     * @param bool         $perItem   Whether the answer can differ per item, e.g. for "edit_own" or when rules decide
     * @param list<string> $grantedBy The user's roles that have the ability
     */
    public function __construct(
        public bool $granted,
        public array $reasons,
        public bool $perItem = false,
        public array $grantedBy = [],
    ) {
    }
}
