<?php

declare(strict_types=1);

namespace Quire\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\Comparator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sets up Doctrine Migrations for the migrations of Quire core and its extensions.
 *
 * They're kept apart from the application's own migrations (doctrine:migrations:*): they run with
 * "quire:migrations:migrate", in Quire's order, and are recorded in Quire's schema. So an application
 * that Quire is added to keeps its migrations, its migration table and its ordering untouched.
 *
 * @internal
 */
final readonly class MigrationsFactory
{
    /** Service ID of the {@see DependencyFactory} for Quire's migrations */
    public const string SERVICE = 'quire.migrations.dependency_factory';

    /**
     * @param list<array{namespace: string, directory: string, owner: string}> $migrations
     */
    public function __construct(
        private Connection $connection,
        private MigrationOrder $order,
        #[Autowire(param: MigrationOrder::PARAMETER)]
        private array $migrations,
    ) {
    }

    public function create(): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => array_column($this->migrations, 'directory', 'namespace'),
                // Only shown by the commands: MigrationStorage keeps the table.
                'table_storage' => ['table_name' => MigrationStorage::TABLE],
                'all_or_nothing' => true,
                'transactional' => true,
            ]),
            new ExistingConnection($this->connection),
        );

        $factory->setService(Comparator::class, $this->order);
        $factory->setService(MetadataStorage::class, new MigrationStorage($this->connection, $this->order));

        return $factory;
    }
}
