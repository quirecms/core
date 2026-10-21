<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * The kinds of values a content field can hold, and how each is stored in the item's JSONB "fields".
 *
 * @api
 */
enum FieldType: string
{
    /** A single line of text */
    case String = 'string';
    /** Longer text, e.g. a summary; can't be indexed */
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    /** One of a fixed list of choices */
    case Enum = 'enum';
    /** A list of strings, e.g. tags */
    case List = 'list';
    /** A calendar date, stored as "2026-12-24" */
    case Date = 'date';
    /** A point in time, stored in UTC as "2026-12-24T18:00:00+00:00" so it sorts as text */
    case DateTime = 'datetime';
    /** Any JSON-encodable value; can't be indexed */
    case Json = 'json';

    public function isNumeric(): bool
    {
        return self::Int === $this || self::Float === $this;
    }

    public function canBeSorted(): bool
    {
        return !\in_array($this, [self::Text, self::List, self::Json], true);
    }

    public function canBeFiltered(): bool
    {
        return !\in_array($this, [self::Text, self::Json], true);
    }
}
