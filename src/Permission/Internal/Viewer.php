<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Whoever is looking at the site right now, and what they may see: signed in or not, from a request or
 * the command line (where nobody is signed in).
 *
 * @internal
 */
final readonly class Viewer
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function can(string $ability, mixed $subject = null): bool
    {
        return $this->security->isGranted($ability, $subject);
    }

    public function canViewType(string $type): bool
    {
        return $this->can(ContentAbilities::name($type, 'view'));
    }

    public function canPreviewType(string $type): bool
    {
        return $this->can(ContentAbilities::name($type, 'view_drafts'));
    }

    public function isSignedIn(): bool
    {
        return $this->security->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface;
    }
}
