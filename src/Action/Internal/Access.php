<?php

declare(strict_types=1);

namespace Quire\Action\Internal;

use Quire\Permission\Delegation;
use Quire\Settings\SettingAccess;
use Quire\User\CurrentUser;
use Quire\User\Security\Confirmation;
use Quire\User\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * For core's actions that take one of the site management abilities, e.g. "quire.users.manage": and,
 * for those that change who may do what or run new code, that the actor holds what they give or act
 * on, and confirmed it's them (D-42).
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
        private Delegation $delegation,
        private Confirmation $confirmation,
        private Security $security,
        private SettingAccess $settings,
    ) {
    }

    /**
     * Whether the actor may set the setting to the value: settings another part of the admin owns take
     * its ability too, and the permission settings only abilities the actor holds.
     */
    public function maySet(string $key, mixed $value): ?string
    {
        $actor = $this->security->getUser();

        return $actor instanceof \Symfony\Component\Security\Core\User\UserInterface ? $this->settings->refusal($actor, $key, $value) : 'Only someone signed in changes settings.';
    }

    /**
     * The ability, and a confirmation that it's them: for what runs new code or changes access.
     */
    public function confirmed(string $ability): ?string
    {
        return $this->denied($ability) ?? $this->confirmation->refusal();
    }

    /**
     * Null when the actor confirmed it's them in the last few minutes, otherwise why not.
     */
    public function unconfirmed(): ?string
    {
        return $this->confirmation->refusal();
    }

    /**
     * Whether the actor may manage the user: only someone who holds everything they hold. Managing
     * oneself isn't asked here (giving oneself roles is, by {@see self::mayGiveRoles()}).
     */
    public function mayManage(User $target): ?string
    {
        $actor = $this->security->getUser();

        if (!$actor instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return 'Only someone signed in manages users.';
        }

        $beyond = $this->delegation->beyond($actor, $target);

        return [] === $beyond ? null : \sprintf('You can\'t manage %s: they can do things you can\'t (%s).', $target->displayName, $this->delegation->labels($beyond));
    }

    /**
     * Whether the actor may give these roles: only roles whose abilities they hold.
     *
     * @param list<string> $roles
     */
    public function mayGiveRoles(array $roles): ?string
    {
        $actor = $this->security->getUser();

        if (!$actor instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            return 'Only someone signed in gives roles.';
        }

        foreach ($this->delegation->rolesBeyond($actor, $roles) as $role => $missing) {
            return \sprintf('You can\'t give the role "%s": it can do things you can\'t (%s).', $role, $this->delegation->labels($missing));
        }

        return null;
    }

    /**
     * Whether the actor may grant or revoke these abilities: only those they hold.
     *
     * @param list<string> $abilities
     */
    public function mayGrant(array $abilities): ?string
    {
        $actor = $this->security->getUser();
        $missing = $actor instanceof \Symfony\Component\Security\Core\User\UserInterface ? $this->delegation->missing($actor, $abilities) : $abilities;

        return [] === $missing ? null : \sprintf('You can\'t give or take away %s: you don\'t have it yourself.', $this->delegation->labels($missing));
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
