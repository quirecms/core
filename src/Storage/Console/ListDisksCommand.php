<?php

declare(strict_types=1);

namespace Quire\Storage\Console;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;
use Quire\Storage\Internal\InstalledDisks;
use Quire\Storage\StorageDisks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:storage:list',
    description: 'List the storage disks: where they are, who declared them, and what they hold',
)]
final readonly class ListDisksCommand
{
    public function __construct(
        private StorageDisks $disks,
        private InstalledDisks $installed,
        private Connection $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $counts = [];

        foreach ($this->connection->fetchAllAssociative('SELECT disk, count(*) AS files, sum(size) AS bytes FROM quire.storage_object GROUP BY disk ORDER BY disk') as $row) {
            $row = new Row($row);
            $counts[$row->string('disk')] = [$row->int('files'), $row->int('bytes')];
        }

        // Disabled extensions' disks too: their files are kept, and backed up.
        $all = $this->installed->all();

        if ([] === $all->names()) {
            $io->writeln('No storage disks. Extensions declare them with Extend\Storage; the site adds its own in "quire.storage.disks".');
        } else {
            $rows = [];

            foreach ($all->names() as $name) {
                $disk = $all->get($name);
                [$files, $bytes] = $counts[$name] ?? [0, 0];
                $rows[] = [
                    $name,
                    ($disk->public ? 'public' : 'private').($this->disks->has($name) ? '' : ' (not in use: the extension is disabled)'),
                    $disk->location,
                    $disk->owner,
                    \sprintf('%d (%s)', $files, Helper::formatMemory($bytes)),
                ];
            }

            $io->table(['Disk', 'Files are', 'Where', 'Declared by', 'Stored files'], $rows);
        }

        foreach ($all->dormant() as $name) {
            $io->warning(\sprintf('"quire.storage.disks.%s" places a disk that no installed extension declares, so it isn\'t used. Set its "public" to make it a disk of the site\'s own, or remove it.', $name));
        }

        foreach (array_diff(array_keys($counts), $all->names()) as $name) {
            $io->warning(\sprintf('%d stored file(s) are on the disk "%s", which neither the site nor any installed extension has: backups can\'t copy them. Install the extension that declares it, or configure it in "quire.storage.disks".', $counts[$name][0], $name));
        }

        return Command::SUCCESS;
    }
}
