<?php

declare(strict_types=1);

namespace Quire\Content\Field\Type;

use Quire\Content\Content;
use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Internal\Row;

/**
 * Links to other content items:
 *
 *     ->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'posts'])
 *     ->field('series', 'relation', options: ['types' => ['series'], 'multiple' => false])
 *
 * Options: "types" (the types it links to; required), "multiple" (a list, in order, or one item;
 * default true), "limit" (most items in the list) and "inverse" (the name of the relation on the linked
 * types that lists the items linking to them, for `->with()`).
 *
 * The value is the ID of the linked item, or the list of IDs. The writer checks that the items exist,
 * aren't in the trash and are of the right types, and keeps quire.content_relation in step, so the
 * database refuses links to items that don't exist, and deleting an item for good removes the links to it.
 *
 * @internal
 */
final class RelationField extends AbstractFieldType
{
    public const string TYPES = 'types';

    public const string MULTIPLE = 'multiple';

    public const string LIMIT = 'limit';

    public const string INVERSE = 'inverse';

    public static function isMultiple(FieldDefinition $field): bool
    {
        return false !== ($field->options[self::MULTIPLE] ?? true);
    }

    /**
     * @return list<string>
     */
    public static function targetTypes(FieldDefinition $field): array
    {
        $types = $field->options[self::TYPES] ?? [];

        return \is_array($types) ? array_values(array_filter($types, \is_string(...))) : [];
    }

    public function options(): array
    {
        return [self::TYPES, self::MULTIPLE, self::LIMIT, self::INVERSE];
    }

    public function definitionError(FieldDefinition $field): ?string
    {
        $types = $field->options[self::TYPES] ?? null;
        $limit = $field->options[self::LIMIT] ?? null;
        $inverse = $field->options[self::INVERSE] ?? null;

        return match (true) {
            !\is_array($types) || [] === $types || !array_is_list($types) || !array_all($types, static fn (mixed $type): bool => \is_string($type) && 1 === preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type)) => 'Relations need "types": the list of content types they link to, e.g. ["tag"].',
            !\is_bool($field->options[self::MULTIPLE] ?? true) => '"multiple" is true (a list) or false (one item).',
            null !== $limit && (!\is_int($limit) || $limit < 1 || !self::isMultiple($field)) => '"limit" is the most items of a list: a whole number from 1, for relations with "multiple".',
            null !== $inverse && (!\is_string($inverse) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $inverse)) => '"inverse" is a name such as "posts": a lowercase letter, then lowercase letters, digits or underscores.',
            null !== $field->default => 'Relations have no default value.',
            default => null,
        };
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Relation;
    }

    /**
     * Items become their IDs.
     */
    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        $id = static fn (mixed $item): mixed => $item instanceof Content ? $item->id : $item;

        return \is_array($value) ? array_map($id, $value) : $id($value);
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        if (!self::isMultiple($field)) {
            return \is_string($value) && Row::isUuid($value) ? null : 'Expected the ID of a content item.';
        }

        if (!\is_array($value) || !array_is_list($value) || !array_all($value, static fn (mixed $id): bool => \is_string($id) && Row::isUuid($id))) {
            return 'Expected a list of IDs of content items.';
        }

        /** @var list<string> $value */
        $limit = $field->options[self::LIMIT] ?? null;

        return match (true) {
            \count($value) !== \count(array_unique($value)) => 'Each item can be linked once.',
            \is_int($limit) && \count($value) > $limit => \sprintf('At most %d items.', $limit),
            default => null,
        };
    }

    public function queryValue(mixed $value, FieldDefinition $field): ?string
    {
        $id = $value instanceof Content ? $value->id : $value;

        return \is_string($id) ? $id : null;
    }

    public function expected(FieldDefinition $field): string
    {
        return 'a content item or its ID';
    }
}
