<?php

declare(strict_types=1);

namespace Quire\Content\Type;

/**
 * The capability flags core understands. Extensions can add their own (e.g. "commentable") and check
 * for them with {@see ContentTypeDefinition::has()}.
 *
 * @api
 */
final class Capability
{
    /** Items form a tree (parent and position), and permalinks can use the {path} of their ancestors. */
    public const string HIERARCHICAL = 'hierarchical';

    /** Each save keeps an immutable revision that can be restored. */
    public const string REVISIONS = 'revisions';

    /** Items can be published at a future date. */
    public const string SCHEDULING = 'scheduling';

    /** Items have a short summary, written or derived from the body. */
    public const string EXCERPT = 'excerpt';

    /** Items appear in paginated listings and feeds. */
    public const string LISTABLE = 'listable';

    /** Items are included in site search. */
    public const string SEARCHABLE = 'searchable';

    /** Items have an author. */
    public const string AUTHOR = 'author';

    /** @var list<string> */
    public const array CORE = [self::HIERARCHICAL, self::REVISIONS, self::SCHEDULING, self::EXCERPT, self::LISTABLE, self::SEARCHABLE, self::AUTHOR];

    private function __construct()
    {
    }
}
