<?php

declare(strict_types=1);

namespace Quire\Action;

/**
 * What kind of value a parameter takes. References (content, users, …) are given by their ID or name,
 * as strings, so arguments can be written as JSON, e.g. by an API or in the audit log.
 *
 * @api
 */
enum ParameterType: string
{
    case Text = 'text';

    case Bool = 'bool';

    case Integer = 'integer';

    /** A time with its offset or time zone, as ISO 8601, e.g. "2026-12-24T09:00:00+01:00" */
    case DateTime = 'datetime';

    /** Any value that JSON can hold, e.g. a setting's */
    case Value = 'value';

    /** A content item's ID */
    case Content = 'content';

    /** A pending revision's ID: changes to an item, not published yet */
    case PendingRevision = 'pending_revision';

    /** A content type's ID, e.g. "post" */
    case ContentType = 'content_type';

    /** A user's ID, email or username */
    case User = 'user';

    /** A role's name, e.g. "editor" */
    case Role = 'role';

    /** An ability, e.g. "content.post.publish" */
    case Ability = 'ability';

    /** A setting's key, e.g. "site.title" */
    case Setting = 'setting';

    /** A theme's ID */
    case Theme = 'theme';

    /** An extension's ID */
    case Extension = 'extension';

    /** Whether values of this type are strings (all but booleans, integers and JSON values). */
    public function isText(): bool
    {
        return !\in_array($this, [self::Bool, self::Integer, self::Value], true);
    }
}
