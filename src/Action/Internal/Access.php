<?php

declare(strict_types=1);

namespace Quire\Action\Internal;

use Quire\User\CurrentUser;
use Quire\User\User;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * For core's actions that take one of the site management abilities, e.g. "quire.users.manage".
 *
 * @internal
 */
final readonly class Access
{
    public const string USERS = 'quire.users.manage';

    public const string PERMISSIONS = 'quire.permissions.manage';

    public const string SETTINGS = 'quire.settings.manage';

    public const string THEMES = 'quire.themes.manage';

    public const string EXTENSIONS = 'quire.extensions.manage';

    private const array REASONS = [
        self::USERS => 'Managing users takes the right to manage them; ask an admin.',
        self::PERMISSIONS => 'Changing roles takes the right to manage permissions; ask an admin.',
        self::SETTINGS => 'Changing settings takes the right to manage them; ask an admin.',
        self::THEMES => 'Changing the theme takes the right to manage themes; ask an admin.',
        self::EXTENSIONS => 'Enabling and disabling extensions takes the right to manage them; ask an admin.',
    ];

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private CurrentUser $users,
    ) {
    }

    public function denied(string $ability): ?string
    {
        return $this->authorization->isGranted($ability) ? null : self::REASONS[$ability] ?? throw new \LogicException(\sprintf('No reason for "%s".', $ability));
    }

    public function actor(): ?User
    {
        return $this->users->get();
    }
}
