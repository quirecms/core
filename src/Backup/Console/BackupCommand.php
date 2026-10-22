<?php

declare(strict_types=1);

namespace Quire\Backup\Console;

use Quire\Backup\Backups;
use Quire\Backup\Exception\BackupException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'quire:backup',
    description: 'Back up Quire\'s data: content, settings, extensions\' data and the migration history',
    help: <<<'HELP'
        Writes Quire's data to a file with pg_dump, in PostgreSQL's custom format. Only the "quire"
        schema is included, so an application that shares the database isn't part of the backup.

          <info>%command.full_name%</info>                       (writes var/backups/quire-<date>-<time>.dump)
          <info>%command.full_name% /backups/site.dump</info>
          <info>%command.full_name% - | gzip > site.dump.gz</info>   (writes to standard output)

        Restore it with <info>quire:restore</info>. The file holds all of the site's data: keep it as safe as
        the database itself.
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
    ): int {
        try {
            if ('-' === $file) {
                $this->backups->stream(static function (string $data): void {
                    fwrite(\STDOUT, $data);
                });

                return Command::SUCCESS;
            }

            $file ??= $this->defaultFile();
            $this->backups->create($file);
        } catch (BackupException $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Backed up to %s (%s).', $file, $this->size((int) filesize($file))));

        return Command::SUCCESS;
    }

    private function defaultFile(): string
    {
        $directory = $this->projectDir.'/var/backups';

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new BackupException(\sprintf('Can\'t create the directory "%s". Pass a file name to write the backup somewhere else.', $directory));
        }

        return \sprintf('%s/quire-%s.dump', $directory, new \DateTimeImmutable()->format('Ymd-His'));
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
