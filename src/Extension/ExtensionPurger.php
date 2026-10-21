<?php

declare(strict_types=1);

namespace Quire\Extension;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Database\MigrationOrder;
use Quire\Extension\Event\ExtensionPurging;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Permanently deletes extensions' data: lets each one clean up, rolls back its migrations and
 * deletes its settings.
 *
 * Only extensions that run in the current process can be purged, because their migrations must be
 * registered to be rolled back.
 */
final readonly class ExtensionPurger implements ExtensionPurgerInterface
{
    /**
     * @param list<array{namespace: string, owner: string}> $migrationNamespaces
     */
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
        private Connection $connection,
        private EventDispatcherInterface $dispatcher,
        #[Autowire(param: MigrationOrder::PARAMETER)]
        private array $migrationNamespaces,
    ) {
    }

    /**
     * @param list<ExtensionManifest> $extensions In the order to purge them: dependents first
     * @param \Closure(string): void  $output
     */
    public function purge(array $extensions, \Closure $output): void
    {
        foreach ($extensions as $extension) {
            $output(\sprintf('Purging "%s"…', $extension->id));

            $this->dispatcher->dispatch(new ExtensionPurging($extension));

            $rolledBack = $this->rollBackMigrations($extension->id);
            $output(\sprintf('  Rolled back %d migration(s).', $rolledBack));

            $deleted = $this->connection->executeStatement(
                'DELETE FROM setting WHERE name LIKE :prefix',
                ['prefix' => addcslashes($extension->id, '%_\\').'.%'],
            );
            $output(\sprintf('  Deleted %d stored setting(s).', $deleted));
        }
    }

    /**
     * @return int Number of migrations rolled back
     */
    private function rollBackMigrations(string $owner): int
    {
        $namespaces = array_column(array_filter($this->migrationNamespaces, static fn (array $entry): bool => $entry['owner'] === $owner), 'namespace');

        if ([] === $namespaces) {
            return 0;
        }

        $metadata = $this->migrations->getMetadataStorage();
        $metadata->ensureInitialized();
        $executed = $metadata->getExecutedMigrations();

        $versions = array_values(array_filter(
            array_map(static fn (\Doctrine\Migrations\Metadata\AvailableMigration $available): Version => $available->getVersion(), $this->migrations->getMigrationRepository()->getMigrations()->getItems()),
            static fn (Version $version): bool => $executed->hasMigration($version)
                && array_any($namespaces, static fn (string $namespace): bool => str_starts_with((string) $version, $namespace.'\\')),
        ));

        if ([] === $versions) {
            return 0;
        }

        // Doctrine plans a rollback newest first, following MigrationOrder.
        $plan = $this->migrations->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::DOWN);
        $this->migrations->getMigrator()->migrate($plan, new MigratorConfiguration()->setAllOrNothing(true));

        return \count($versions);
    }
}
