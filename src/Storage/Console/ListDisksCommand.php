<?php

declare(strict_types=1);

namespace Quire\Storage\Console;

use Doctrine\DBAL\Connection;
use Quire\Content\Internal\Row;
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
        private Connection $connection,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ([] === $this->disks->names()) {
            $io->writeln('No storage disks. Extensions declare them with Extend\Storage; the site adds its own in "quire.storage.disks".');

            return Command::SUCCESS;
        }

        $counts = [];

        foreach ($this->connection->fetchAllAssociative('SELECT disk, count(*) AS files, sum(size) AS bytes FROM quire.storage_object GROUP BY disk') as $row) {
            $row = new Row($row);
            $counts[$row->string('disk')] = [$row->int('files'), $row->int('bytes')];
        }

        $rows = [];

        foreach ($this->disks->names() as $name) {
            $disk = $this->disks->get($name);
            [$files, $bytes] = $counts[$name] ?? [0, 0];
            $rows[] = [$name, $disk->public ? 'public' : 'private', $disk->location, $disk->owner, \sprintf('%d (%s)', $files, Helper::formatMemory($bytes))];
        }

        $io->table(['Disk', 'Files are', 'Where', 'Declared by', 'Stored files'], $rows);

        return Command::SUCCESS;
    }
}
