<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * Quire's built-in roles. Extensions add roles with Extend\Permissions::role().
 *
 * @api
 */
final class Role
{
    /**
     * Everyone, signed in or not. Not a role users are given: abilities granted to guests are granted
     * to all.
     */
    public const string GUEST = 'guest';

    /** A registered user, e.g. one who can comment */
    public const string MEMBER = 'member';

    /** Writes and publishes content */
    public const string EDITOR = 'editor';

    /** Runs the site */
    public const string ADMIN = 'admin';

    /** @var list<string> The roles core declares */
    public const array ALL = [self::MEMBER, self::EDITOR, self::ADMIN];
}
