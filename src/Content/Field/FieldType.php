<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * The field types of Quire's core, by name. Extensions add more with Extend\FieldTypes.
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
    /** Links to other content items, e.g. a post's tags or a page's related pages */
    case Relation = 'relation';

    /**
     * @return class-string<FieldTypeInterface>
     */
    public function class(): string
    {
        return match ($this) {
            self::String => Type\StringField::class,
            self::Text => Type\TextField::class,
            self::Int => Type\IntField::class,
            self::Float => Type\FloatField::class,
            self::Bool => Type\BoolField::class,
            self::Enum => Type\EnumField::class,
            self::List => Type\ListField::class,
            self::Date => Type\DateField::class,
            self::DateTime => Type\DateTimeField::class,
            self::Json => Type\JsonField::class,
            self::Relation => Type\RelationField::class,
        };
    }
}
