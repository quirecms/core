<?php

declare(strict_types=1);

namespace Quire\Backup\Console;

use Quire\Backup\BackupFiles;
use Quire\Backup\BackupReport;
use Quire\Backup\Backups;
use Quire\Backup\Exception\BackupException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'quire:backup',
    description: 'Back up the site: Quire\'s data, the migration history, and the stored files',
    help: <<<'HELP'
        Writes a tar archive of Quire's data (with pg_dump; only the "quire" schema, so an application that
        shares the database isn't part of it) and of the stored files on local disks:

          <info>%command.full_name%</info>                          (writes var/backups/quire-<date>-<time>.tar)
          <info>%command.full_name% /backups/site.tar</info>
          <info>%command.full_name% - | gzip > site.tar.gz</info>       (writes to standard output)
          <info>%command.full_name% --files=all</info>              (files on S3 disks too)
          <info>%command.full_name% --files-dir=/backups/files</info>   (files there, each copied once)

        With <comment>--files-dir</comment>, the files go into that directory instead of the backup, named after their content
        like on the disks, so each backup copies only the files that are new; keep the directory with the
        backups. Every file is checked against its checksum on the way.

        Restore it with <info>quire:restore</info>. It holds all of the site's data: keep it as safe as the database.
        HELP,
)]
final readonly class BackupCommand
{
    public function __construct(
        private Backups $backups,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The file to write, or "-" for standard output')]
        ?string $file = null,
        #[Option(description: 'Which stored files to copy: "local" (files on local disks), "all" (S3 disks too) or "none"')]
        string $files = 'local',
        #[Option(description: 'Copy the files into this directory instead of the backup, each only once')]
        ?string $filesDir = null,
    ): int {
        $errors = $io->getErrorStyle();
        $selection = BackupFiles::tryFrom($files);

        if (null === $selection) {
            $errors->error(\sprintf('--files is "local", "all" or "none", not "%s".', $files));

            return Command::FAILURE;
        }

        try {
            if ('-' === $file) {
                $report = $this->backups->stream(static function (string $data): void {
                    fwrite(\STDOUT, $data);
                }, $selection, $filesDir);
                // Standard output is the backup, so messages go to standard error.
                $this->warn($errors, $report);

                return Command::SUCCESS;
            }

            $file ??= $this->defaultFile();
            $report = $this->backups->create($file, $selection, $filesDir);
        } catch (BackupException $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Backed up to %s (%s): the database, and %s.', $file, $this->size((int) filesize($file)), $this->files($report, $filesDir)));
        $this->warn($io, $report);

        return Command::SUCCESS;
    }

    private function files(BackupReport $report, ?string $filesDir): string
    {
        $copied = match (true) {
            0 === $report->objects => 'no stored files (there are none)',
            null !== $filesDir => \sprintf('%d new stored file(s) in %s (%d were there from earlier backups)', $report->copied, $filesDir, $report->reused),
            default => \sprintf('%d of %d stored file(s)', $report->copied, $report->objects),
        };

        return $copied.(0 === $report->notCopied ? '' : \sprintf('; %d on disks it doesn\'t copy (see --files)', $report->notCopied));
    }

    private function warn(SymfonyStyle $io, BackupReport $report): void
    {
        foreach (['missing' => $report->missing, 'damaged (their content doesn\'t match their checksum)' => $report->corrupt] as $what => $names) {
            if ([] !== $names) {
                $io->warning([\sprintf('%d stored file(s) were %s, so the backup has no good copy of them:', \count($names), $what), ...\array_slice($names, 0, 20), ...(\count($names) > 20 ? ['…'] : [])]);
            }
        }
    }

    private function defaultFile(): string
    {
        $directory = $this->projectDir.'/var/backups';

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new BackupException(\sprintf('Can\'t create the directory "%s". Pass a file name to write the backup somewhere else.', $directory));
        }

        return \sprintf('%s/quire-%s.tar', $directory, new \DateTimeImmutable()->format('Ymd-His'));
    }

    private function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => \sprintf('%.1f GB', $bytes / 1024 ** 3),
            $bytes >= 1024 ** 2 => \sprintf('%.1f MB', $bytes / 1024 ** 2),
            default => \sprintf('%.1f kB', $bytes / 1024),
        };
    }
}
