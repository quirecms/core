<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * How a field's values are kept in the item's JSONB "fields", which decides how they're compared,
 * sorted and indexed. Every field type maps its fields to one of these.
 *
 * @api
 */
enum FieldStorage
{
    /** Text, compared and sorted as text (dates in ISO 8601 sort correctly as text) */
    case Text;
    /** Numbers, compared and sorted numerically */
    case Number;
    case Boolean;
    /** A list of texts, filtered by containment ("contains"), not sorted */
    case TextList;
    /** Any JSON value, e.g. long text or nested data: never sorted, filtered or indexed */
    case Unindexed;
    /**
     * IDs of other content items: kept in the item's fields, and in quire.content_relation, which the
     * database keeps consistent and which filters use (see {@see Type\RelationField}). Not sorted.
     */
    case Relation;

    public function canBeSorted(): bool
    {
        return \in_array($this, [self::Text, self::Number, self::Boolean], true);
    }

    public function canBeFiltered(): bool
    {
        return self::Unindexed !== $this;
    }

    /**
     * Whether the field needs an index of its own on quire.content to be sorted or filtered: relations are
     * indexed in quire.content_relation.
     */
    public function needsIndex(): bool
    {
        return self::Relation !== $this && self::Unindexed !== $this;
    }
}
