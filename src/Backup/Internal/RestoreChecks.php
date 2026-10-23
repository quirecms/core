<?php

declare(strict_types=1);

namespace Quire\Backup\Internal;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Finder\RecursiveRegexFinder;
use Quire\Backup\BackupContents;
use Quire\Database\DatabaseSchema;
use Quire\Database\MigrationOrder;
use Quire\Extend\ExtenderException;
use Quire\Extend\Storage;
use Quire\Extension\Boot\ExtensionBooter;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Storage\StorageDisks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Decides whether a backup can be restored here, before anything is changed.
 *
 * A backup needs code at least as new as the code that made it: every migration that had run must exist
 * in the code, for Quire core and for each extension the backup has enabled. Older backups are fine;
 * their missing migrations run after the restore.
 *
 * @internal
 */
final readonly class RestoreChecks
{
    /** Where a restore keeps the data it replaces until it has succeeded */
    public const string REPLACED_SCHEMA = DatabaseSchema::NAME.'_before_restore';

    public function __construct(
        private Connection $connection,
        private InstalledExtensions $installed,
        private ExtensionStateStoreInterface $state,
        private StorageDisks $disks,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    /**
     * @param string|null $filesDirectory Where the copies of stored files are, for backups made with one
     *
     * @return list<string> Why the backup can't be restored; empty when it can
     */
    public function problems(BackupContents $backup, ?string $filesDirectory = null): array
    {
        $enabled = $this->enabled($backup);
        $problems = [];

        foreach ($enabled as $id) {
            if (!$this->installed->has($id)) {
                $problems[] = \sprintf('The backup has the extension "%s" enabled, which isn\'t installed here. Install it with Composer first.', $id);
            }
        }

        try {
            $container = $this->boot(array_values(array_filter($enabled, $this->installed->has(...))));
        } catch (ExtenderException $e) {
            return [...$problems, \sprintf('The extensions the backup has enabled can\'t be loaded: %s', $e->getMessage())];
        }

        /** @var list<array{namespace: string, directory: string, owner: string}> $namespaces */
        $namespaces = $container->getParameter(MigrationOrder::PARAMETER);

        foreach ($namespaces as $registered) {
            $available = new RecursiveRegexFinder()->findMigrations($registered['directory'], $registered['namespace']);
            $unknown = array_values(array_filter(
                $backup->migrations,
                static fn (string $version): bool => str_starts_with($version, $registered['namespace'].'\\') && !\in_array($version, $available, true),
            ));

            if ([] !== $unknown) {
                $problems[] = \sprintf(
                    'The backup was made with a newer version of %s: it has migrations this code doesn\'t have (%s). Update %1$s first.',
                    'core' === $registered['owner'] ? 'Quire' : \sprintf('the extension "%s"', $registered['owner']),
                    implode(', ', array_map(static fn (string $version): string => basename(str_replace('\\', '/', $version)), $unknown)),
                );
            }
        }

        return [...$problems, ...$this->fileProblems($backup, $container, $filesDirectory), ...$this->databaseProblems()];
    }

    /**
     * The disks the site has once the backup is restored: its own, and those of the extensions the backup
     * enables, where they are without configuration.
     */
    public function disksAfterRestore(BackupContents $backup): StorageDisks
    {
        return $this->disks->withDeclarations($this->declaredDisks($this->boot(array_values(array_filter($this->enabled($backup), $this->installed->has(...))))));
    }

    /**
     * Whether Quire's schema exists in the database, and how many content items it has.
     *
     * @return int|null Null when there is no schema yet
     */
    public function currentContentCount(): ?int
    {
        if (!$this->schemaExists(DatabaseSchema::NAME)) {
            return null;
        }

        if (null === $this->connection->fetchOne('SELECT to_regclass(?)', [DatabaseSchema::NAME.'.content'])) {
            return 0;
        }

        $count = $this->connection->fetchOne(\sprintf('SELECT count(*) FROM %s.content', DatabaseSchema::NAME));

        return is_numeric($count) ? (int) $count : 0;
    }

    public function schemaExists(string $name): bool
    {
        return null !== $this->connection->fetchOne('SELECT to_regnamespace(?)', [$name]);
    }

    /**
     * Pinned extensions come from the configuration, whatever the backup has enabled.
     *
     * @return list<string>
     */
    private function enabled(BackupContents $backup): array
    {
        return null === $this->state->readOnlyReason() ? $backup->enabledExtensions : $this->state->enabledIds();
    }

    /**
     * @return list<string>
     */
    private function fileProblems(BackupContents $backup, ContainerBuilder $container, ?string $filesDirectory): array
    {
        $problems = [];
        $disks = $this->disks->withDeclarations($this->declaredDisks($container));

        foreach (array_keys($backup->objects) as $disk) {
            if (!$disks->has($disk)) {
                $problems[] = \sprintf('The backup has files on the disk "%s", which this site doesn\'t have. Install the extension that declares it, or configure it in "quire.storage.disks".', $disk);
            }
        }

        if ('directory' === $backup->files && null === $filesDirectory) {
            $problems[] = 'The backup\'s files are in a files directory, not in the backup: pass it with --files-dir.';
        }

        if (null !== $filesDirectory && !is_dir($filesDirectory)) {
            $problems[] = \sprintf('The files directory "%s" doesn\'t exist.', $filesDirectory);
        }

        return $problems;
    }

    /**
     * @return list<array{name: string, public: bool, label: ?string, owner: string}>
     */
    private function declaredDisks(ContainerBuilder $container): array
    {
        /** @var list<array{name: string, public: bool, label: ?string, owner: string}> $declared */
        $declared = $container->getParameterBag()->all()[Storage::PARAMETER] ?? [];

        return $declared;
    }

    /**
     * The extenders of the given extensions, run in a container of their own, as they would when the
     * application is built after the restore: what migrations and disks the code has then.
     *
     * @param list<string> $enabledIds
     */
    private function boot(array $enabledIds): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.environment', $this->environment);
        $container->setParameter('kernel.debug', false);

        new ExtensionBooter($this->installed)->boot(
            $container,
            $enabledIds,
            $this->projectDir.\DIRECTORY_SEPARATOR.'extend.php',
            ExtensionBooter::coreExtendFile(),
        );

        return $container;
    }

    /**
     * @return list<string>
     */
    private function databaseProblems(): array
    {
        if ($this->schemaExists(self::REPLACED_SCHEMA)) {
            return [\sprintf('The schema "%s" exists: an earlier restore didn\'t finish, and it holds the data from before it. Rename it to "%s" to get that data back, or drop it, then try again.', self::REPLACED_SCHEMA, DatabaseSchema::NAME)];
        }

        if (!$this->schemaExists(DatabaseSchema::NAME)) {
            return [];
        }

        // Objects of the application that depend on Quire's tables would be dropped with them.
        /** @var list<string> $dependents */
        $dependents = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT format('the foreign key %I on %s', c.conname, c.conrelid::regclass)
            FROM pg_constraint c
            JOIN pg_class referenced ON referenced.oid = c.confrelid
            JOIN pg_class referencing ON referencing.oid = c.conrelid
            WHERE c.contype = 'f' AND referenced.relnamespace = to_regnamespace(:schema) AND referencing.relnamespace <> to_regnamespace(:schema)
            UNION
            SELECT format('the view %s', v.oid::regclass)
            FROM pg_depend d
            JOIN pg_rewrite r ON r.oid = d.objid
            JOIN pg_class v ON v.oid = r.ev_class
            JOIN pg_class t ON t.oid = d.refobjid
            WHERE d.classid = 'pg_rewrite'::regclass AND d.refclassid = 'pg_class'::regclass
                AND t.relnamespace = to_regnamespace(:schema) AND v.relnamespace <> to_regnamespace(:schema)
            ORDER BY 1
            SQL, ['schema' => DatabaseSchema::NAME]);

        if ([] === $dependents) {
            return [];
        }

        return [\sprintf('Restoring replaces Quire\'s tables, but these objects outside the "%s" schema depend on them: %s. Drop them before restoring and create them again afterwards.', DatabaseSchema::NAME, implode(', ', $dependents))];
    }
}
