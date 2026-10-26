<?php

declare(strict_types=1);

namespace Quire\Permission;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What another user may do, decided as is_granted() decides for the signed-in one: their roles (the
 * host application's mapped too), the site's grants, and extensions' rules for a subject.
 *
 *     $access->for($kim)->allows('content.post.publish');
 *     $access->for($kim)->allows('content.post.edit', $item);
 *
 * To know why, see {@see AccessExplainer}.
 *
 * @api
 */
final readonly class Access
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function for(UserInterface $user): UserAccess
    {
        return new UserAccess($this->security, $user);
    }
}
