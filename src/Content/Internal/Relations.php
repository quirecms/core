<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

use Doctrine\DBAL\Connection;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Field\Type\RelationField;
use Quire\Content\Type\ContentTypeDefinition;

/**
 * What the content writer does for relation fields that needs the database: checking the linked items,
 * and keeping quire.content_relation in step with the items' fields.
 *
 * @internal
 */
final readonly class Relations
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Why the links being saved can't be made: items that don't exist, are in the trash, are of a type the
     * field doesn't link to, or are the item itself.
     *
     * @param array<string, mixed>|null $before The item's fields before, or null for a new item
     * @param array<string, mixed>      $after  The fields to save, normalized
     *
     * @return array<string, string> Messages keyed by "fields.<name>"
     */
    public function violations(ContentTypeDefinition $type, ?string $selfId, ?array $before, array $after): array
    {
        $changed = $this->changed($type, $before, $after);
        $ids = array_values(array_unique(array_merge(...array_values($changed))));

        if ([] === $ids) {
            return [];
        }

        $found = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, type, deleted_at IS NOT NULL AS trashed FROM quire.content WHERE id = ANY(CAST(? AS uuid[]))', [$this->array($ids)]) as $row) {
            $found[new Row($row)->string('id')] = ['type' => new Row($row)->string('type'), 'trashed' => true === $row['trashed']];
        }

        $violations = [];

        foreach ($changed as $name => $targets) {
            $field = $type->fields[$name];
            $allowed = RelationField::targetTypes($field);

            foreach ($targets as $id) {
                $message = match (true) {
                    $id === $selfId => 'An item can\'t be linked to itself.',
                    !isset($found[$id]) => \sprintf('No content item has the ID "%s".', $id),
                    $found[$id]['trashed'] => \sprintf('The item "%s" is in the trash.', $id),
                    !\in_array($found[$id]['type'], $allowed, true) => \sprintf('Only items of the type%s %s can be linked here; "%s" is a %s.', \count($allowed) > 1 ? 's' : '', implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $allowed)), $id, $found[$id]['type']),
                    default => null,
                };

                if (null !== $message) {
                    $violations['fields.'.$name] = $message;

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * Writes the links of the relation fields that changed.
     *
     * @param array<string, mixed>      $after  The item's fields as saved
     * @param array<string, mixed>|null $before Its fields before, or null for a new item
     */
    public function sync(string $sourceId, ContentTypeDefinition $type, array $after, ?array $before): void
    {
        foreach ($this->relationFields($type) as $name => $field) {
            $value = $after[$name] ?? null;

            if (null !== $before && ($before[$name] ?? null) === $value) {
                continue;
            }

            $this->connection->executeStatement('DELETE FROM quire.content_relation WHERE source_id = ? AND field = ?', [$sourceId, $name]);
            $targets = $this->targets($field, $value);

            if ([] !== $targets) {
                $this->connection->executeStatement(
                    'INSERT INTO quire.content_relation (source_id, field, target_id, position)
                     SELECT ?, ?, target, position - 1 FROM unnest(CAST(? AS uuid[])) WITH ORDINALITY AS t(target, position)',
                    [$sourceId, $name, $this->array($targets)],
                );
            }
        }
    }

    /**
     * Before an item is deleted for good: takes it out of the fields of the items that link to it. (Their
     * rows in quire.content_relation go with it, by the foreign key.).
     */
    public function detach(string $targetId): void
    {
        // One field at a time: an UPDATE … FROM changes each item once, even when it links from several fields.
        /** @var list<string> $fields */
        $fields = $this->connection->fetchFirstColumn('SELECT DISTINCT field FROM quire.content_relation WHERE target_id = ?', [$targetId]);

        foreach ($fields as $field) {
            $this->connection->executeStatement(<<<'SQL'
                UPDATE quire.content c
                SET fields = CASE jsonb_typeof(c.fields -> CAST(:field AS text))
                    WHEN 'array' THEN jsonb_set(c.fields, ARRAY[CAST(:field AS text)], (c.fields -> CAST(:field AS text)) - CAST(:target AS text))
                    ELSE c.fields - CAST(:field AS text)
                END
                FROM quire.content_relation r
                WHERE r.target_id = CAST(:target AS uuid) AND r.field = :field AND c.id = r.source_id
                SQL, ['target' => $targetId, 'field' => $field]);
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> Those that are items, in the trash or not
     */
    public function existing(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->connection->fetchFirstColumn('SELECT id FROM quire.content WHERE id = ANY(CAST(? AS uuid[]))', [$this->array($ids)]);

        return $existing;
    }

    /**
     * The fields to bring back from a revision: the fields declared now come from it, values of fields no
     * longer declared stay as they are, and links to items deleted for good since are dropped.
     *
     * @param array<string, mixed> $current  The item's fields now
     * @param array<string, mixed> $revision The revision's fields
     *
     * @return array<string, mixed>
     */
    public function fieldsToRestore(ContentTypeDefinition $type, array $current, array $revision): array
    {
        $fields = array_diff_key($current, $type->fields) + array_intersect_key($revision, $type->fields);

        foreach ($this->relationFields($type) as $name => $field) {
            if (!\array_key_exists($name, $fields)) {
                continue;
            }

            $ids = array_values(array_filter((array) $fields[$name], \is_string(...)));
            $existing = $this->existing($ids);
            $fields[$name] = \is_array($fields[$name]) ? array_values(array_intersect($ids, $existing)) : (\in_array($fields[$name], $existing, true) ? $fields[$name] : null);
        }

        return $fields;
    }

    /**
     * @return array<string, FieldDefinition>
     */
    public function relationFields(ContentTypeDefinition $type): array
    {
        return array_filter($type->fields, static fn (FieldDefinition $field): bool => FieldStorage::Relation === $field->storage());
    }

    /**
     * The linked IDs of each relation field whose value is being set to a valid one (other errors are
     * reported by the field type).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     *
     * @return array<string, list<string>>
     */
    private function changed(ContentTypeDefinition $type, ?array $before, array $after): array
    {
        $changed = [];

        foreach ($this->relationFields($type) as $name => $field) {
            if (!\array_key_exists($name, $after) || (null !== $before && ($before[$name] ?? null) === $after[$name]) || null !== $field->validate($after[$name])) {
                continue;
            }

            $changed[$name] = $this->targets($field, $after[$name]);
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    private function targets(FieldDefinition $field, mixed $value): array
    {
        $ids = RelationField::isMultiple($field) ? (\is_array($value) ? $value : []) : (null === $value ? [] : [$value]);

        return array_values(array_filter($ids, static fn (mixed $id): bool => \is_string($id) && Row::isUuid($id)));
    }

    /**
     * @param list<string> $values
     */
    private function array(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}
