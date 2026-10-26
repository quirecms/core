<?php

declare(strict_types=1);

namespace Quire\Permission;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What one user may do. Get it from {@see Access::for()}.
 *
 * @api
 */
final readonly class UserAccess
{
    /**
     * @internal
     */
    public function __construct(
        private Security $security,
        public UserInterface $user,
    ) {
    }

    /**
     * @param string     $ability E.g. "content.post.publish"
     * @param mixed|null $subject What it's about, e.g. a content item, for extensions' rules
     */
    public function allows(string $ability, mixed $subject = null): bool
    {
        return $this->security->isGrantedForUser($this->user, $ability, $subject);
    }
}
