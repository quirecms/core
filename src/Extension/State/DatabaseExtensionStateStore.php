<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Quire\Extension\Exception\ExtensionStateException;

/**
 * Keeps the enabled extensions in the `extension` table, so they can be changed while the site runs.
 */
final readonly class DatabaseExtensionStateStore implements ExtensionStateStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function enabledIds(): array
    {
        try {
            /** @var list<string> $ids */
            $ids = $this->connection->fetchFirstColumn('SELECT id FROM extension WHERE enabled ORDER BY id');
        } catch (TableNotFoundException $e) {
            throw $this->missingTable($e);
        }

        return $ids;
    }

    public function readOnlyReason(): ?string
    {
        return null;
    }

    public function enable(array $ids): void
    {
        $this->setEnabled($ids, true);
    }

    public function disable(array $ids): void
    {
        $this->setEnabled($ids, false);
    }

    /**
     * @param list<string> $ids
     */
    private function setEnabled(array $ids, bool $enabled): void
    {
        try {
            $this->connection->transactional(static function (Connection $connection) use ($ids, $enabled): void {
                foreach ($ids as $id) {
                    $connection->executeStatement(
                        'INSERT INTO extension (id, enabled, updated_at) VALUES (:id, :enabled, now())
                         ON CONFLICT (id) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = EXCLUDED.updated_at',
                        ['id' => $id, 'enabled' => $enabled],
                        ['enabled' => 'boolean'],
                    );
                }
            });
        } catch (TableNotFoundException $e) {
            throw $this->missingTable($e);
        }
    }

    private function missingTable(TableNotFoundException $e): ExtensionStateException
    {
        return new ExtensionStateException('The "extension" table doesn\'t exist yet. Run "bin/console doctrine:migrations:migrate".', previous: $e);
    }
}
