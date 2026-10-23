<?php

declare(strict_types=1);

namespace Quire\Content\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Database\DatabaseSchema;

/**
 * Keeps one database index per sortable or filterable field, matching what the enabled extensions declare.
 *
 * Instead of migrations, the indexes are reconciled with the declarations: missing ones are created,
 * those no longer declared are dropped, and those a failed build left invalid are rebuilt. That works
 * however extensions are enabled, disabled or updated. Indexes are built concurrently, so writes continue
 * while large tables are indexed.
 *
 * Only indexes whose names start with {@see PREFIX} are managed.
 */
final readonly class FieldIndexes
{
    public const string PREFIX = 'content_field_';

    public function __construct(
        private Connection $connection,
        private ContentTypeRegistry $types,
    ) {
    }

    /**
     * @return array<string, array{type: string, field: string, sql: string}> The indexes the declarations need, by name
     */
    public function desired(): array
    {
        $indexes = [];

        foreach ($this->types->all() as $type) {
            foreach ($type->fields as $field) {
                if (!$field->isIndexed()) {
                    continue;
                }

                // List fields are filtered by containment (@>), which needs GIN. Other fields get a B-tree
                // on (type, value): it serves both filters and sorting within a type.
                $definition = FieldStorage::TextList === $field->storage()
                    ? \sprintf('ON quire.content USING GIN (%s)', FieldExpression::json($field))
                    : \sprintf('ON quire.content (type, %s)', FieldExpression::value($field));

                // The name carries a hash of the definition, so a changed declaration replaces the index.
                $name = \sprintf('%s%s_%s_%s', self::PREFIX, substr($type->id, 0, 16), substr($field->name, 0, 16), substr(hash('sha256', $definition), 0, 8));
                $indexes[$name] = ['type' => $type->id, 'field' => $field->name, 'sql' => \sprintf('CREATE INDEX %s %s', $name, $definition)];
            }
        }

        return $indexes;
    }

    /**
     * @return array<string, bool> Managed indexes that exist, by name, and whether each is valid
     */
    public function existing(): array
    {
        /** @var array<string, bool> $indexes */
        $indexes = $this->connection->fetchAllKeyValue(
            "SELECT idx.relname, i.indisvalid
             FROM pg_index i
             JOIN pg_class idx ON idx.oid = i.indexrelid
             JOIN pg_class tbl ON tbl.oid = i.indrelid
             WHERE tbl.relname = 'content' AND tbl.relnamespace = CAST(? AS regnamespace)
               AND starts_with(idx.relname, ?)
             ORDER BY idx.relname",
            [DatabaseSchema::NAME, self::PREFIX],
        );

        return $indexes;
    }

    /**
     * @param bool                          $dryRun Only report what would change
     * @param (\Closure(string): void)|null $output Receives a line per change
     *
     * @return array{created: list<string>, dropped: list<string>}
     */
    public function sync(bool $dryRun = false, ?\Closure $output = null): array
    {
        $output ??= static function (string $line): void {};
        $desired = $this->desired();
        $existing = $this->existing();
        $result = ['created' => [], 'dropped' => []];

        foreach ($existing as $name => $valid) {
            if (isset($desired[$name]) && $valid) {
                continue;
            }

            $output(\sprintf('%s %s%s', $dryRun ? 'Would drop' : 'Dropping', $name, $valid ? '' : ' (invalid: a build failed)'));

            if (!$dryRun) {
                $this->connection->executeStatement(\sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s.%s', DatabaseSchema::NAME, $this->connection->quoteSingleIdentifier($name)));
            }

            $result['dropped'][] = $name;
            unset($existing[$name]);
        }

        foreach ($desired as $name => $index) {
            if (isset($existing[$name])) {
                continue;
            }

            $output(\sprintf('%s %s for %s.%s', $dryRun ? 'Would create' : 'Creating', $name, $index['type'], $index['field']));

            if (!$dryRun) {
                $this->create($index['sql']);
            }

            $result['created'][] = $name;
        }

        return $result;
    }

    private function create(string $sql): void
    {
        try {
            $this->connection->executeStatement(str_replace('CREATE INDEX ', 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ', $sql));
        } catch (DbalException $e) {
            // Another server may be building the same index right now. If its build fails, the next sync
            // finds the index invalid and rebuilds it; anything else is a real error.
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }
}
