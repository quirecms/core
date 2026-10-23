<?php

declare(strict_types=1);

namespace Quire\Backup\Console;

use Quire\Backup\BackupContents;
use Quire\Backup\Backups;
use Quire\Backup\Exception\BackupException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:restore',
    description: 'Replace Quire\'s data with a backup made by quire:backup',
    help: <<<'HELP'
        Restores a backup made by <info>quire:backup</info>, replacing all of Quire's data in the database.
        An application that shares the database isn't touched.

          <info>%command.full_name% var/backups/quire-20261022-180000.tar</info>
          <info>%command.full_name% site.tar --dry-run</info>     (shows what's in it and whether it can be restored)
          <info>gunzip -c site.tar.gz | %command.full_name% -</info>  (reads standard input)
          <info>%command.full_name% site.tar --files-dir=/backups/files</info>   (for backups made with --files-dir)

        Stored files go to the site's disks wherever they are now, e.g. on S3 when the disks moved there
        since the backup; each is checked against its checksum first. Afterwards, every file the database
        lists is checked to be on its disk.

        The backup needs code at least as new as the code that made it: Quire and every extension it has
        enabled must be installed, in the same version or newer. Migrations it doesn't have yet run after
        the restore. If anything fails, the data from before is put back.

        Restoring over existing data asks for confirmation; pass <comment>--force</comment> to skip it, e.g. in scripts.
        HELP,
)]
final readonly class RestoreCommand
{
    public function __construct(
        private Backups $backups,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The backup file, or "-" for standard input')]
        string $file,
        #[Option(description: 'Show what the backup holds and whether it can be restored, without changing anything')]
        bool $dryRun = false,
        #[Option(description: 'Replace existing data without asking')]
        bool $force = false,
        #[Option(description: 'Where the files are, for a backup made with --files-dir')]
        ?string $filesDir = null,
    ): int {
        $temporary = null;

        try {
            $temporary = '-' === $file ? $this->readStandardInput() : null;

            // Standard input holds the backup, so it can't answer questions too.
            return $this->restore($io, $input->isInteractive() && null === $temporary, $temporary ?? $file, $dryRun, $force, $filesDir);
        } catch (BackupException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (null !== $temporary) {
                @unlink($temporary);
            }
        }
    }

    private function restore(SymfonyStyle $io, bool $interactive, string $file, bool $dryRun, bool $force, ?string $filesDir): int
    {
        $backup = $this->backups->inspect($file);
        $this->describe($io, $backup);
        $problems = $this->backups->problems($backup, $filesDir);

        if ([] !== $problems) {
            $io->error(['The backup can\'t be restored here:', ...$problems]);

            return Command::FAILURE;
        }

        $current = $this->backups->currentContentCount();

        if ($dryRun) {
            $io->success(null === $current ? 'The backup can be restored.' : \sprintf('The backup can be restored. It would replace the current data (%s).', $this->items($current)));

            return Command::SUCCESS;
        }

        if (null !== $current && !$force) {
            $question = \sprintf('This replaces all of Quire\'s data in this database (%s) with the backup\'s. Continue?', $this->items($current));

            if (!$interactive) {
                $io->error('The database already has Quire\'s data. Pass --force to replace it.');

                return Command::FAILURE;
            }

            if (!$io->confirm($question, false)) {
                $io->writeln('Nothing was changed.');

                return Command::SUCCESS;
            }
        }

        $report = $this->backups->restore($file, $io->write(...), $filesDir);
        $io->success(\sprintf('Restored the backup%s.', 0 === $report->restored + $report->alreadyThere ? '' : \sprintf(', and %d stored file(s) (%d were there already)', $report->restored + $report->alreadyThere, $report->alreadyThere)));

        foreach (['are missing from their disks' => $report->missing, 'were damaged in the backup and weren\'t restored' => $report->corrupt] as $what => $names) {
            if ([] !== $names) {
                $io->warning([\sprintf('%d stored file(s) %s:', \count($names), $what), ...\array_slice($names, 0, 20), ...(\count($names) > 20 ? ['…'] : [])]);
            }
        }

        return Command::SUCCESS;
    }

    private function items(int $count): string
    {
        return 1 === $count ? '1 content item' : \sprintf('%d content items', $count);
    }

    private function describe(SymfonyStyle $io, BackupContents $backup): void
    {
        $io->definitionList(
            ['Made' => $backup->createdAt?->format('Y-m-d H:i:s T') ?? 'unknown'],
            ['From' => \sprintf('database "%s", PostgreSQL %s', $backup->database, $backup->serverVersion)],
            ['Tables' => (string) $backup->tables],
            ['Migrations' => (string) \count($backup->migrations)],
            ['Extensions' => [] === $backup->enabledExtensions ? 'none enabled in the database' : implode(', ', $backup->enabledExtensions)],
            ['Stored files' => $this->storedFiles($backup)],
        );

        if ([] !== $backup->missingWhenMade) {
            $io->warning(\sprintf('%d stored file(s) were missing or damaged when the backup was made, so it has no good copy of them.', \count($backup->missingWhenMade)));
        }
    }

    private function storedFiles(BackupContents $backup): string
    {
        if ([] === $backup->objects) {
            return 'none';
        }

        $byDisk = implode(', ', array_map(static fn (string $disk, int $count): string => \sprintf('%s: %d', $disk, $count), array_keys($backup->objects), $backup->objects));
        $copies = match ($backup->files) {
            'archive' => \sprintf('%d copied into the backup', $backup->filesInArchive),
            'directory' => 'copied into a files directory',
            'none' => 'not copied',
            default => 'not copied (a backup of the database only)',
        };

        return \sprintf('%d (%s); %s', array_sum($backup->objects), $byDisk, $copies);
    }

    /**
     * pg_restore reads a backup more than once, so standard input is copied to a file first.
     */
    private function readStandardInput(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quire-restore-');
        $target = false === $path ? false : fopen($path, 'w');

        if (false === $path || false === $target) {
            throw new BackupException('Can\'t create a temporary file for the backup read from standard input.');
        }

        chmod($path, 0o600);
        stream_copy_to_stream(\STDIN, $target);
        fclose($target);

        return $path;
    }
}
