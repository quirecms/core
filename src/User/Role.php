<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * Quire's built-in roles. Visitors who aren't signed in are guests, which isn't a role users have.
 *
 * @api
 */
final class Role
{
    /** A registered user, e.g. one who can comment */
    public const string MEMBER = 'member';

    /** Writes and publishes content */
    public const string EDITOR = 'editor';

    /** Runs the site */
    public const string ADMIN = 'admin';

    /** @var list<string> */
    public const array ALL = [self::MEMBER, self::EDITOR, self::ADMIN];
}
