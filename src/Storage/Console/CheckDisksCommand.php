<?php

declare(strict_types=1);

namespace Quire\Storage\Console;

use Quire\Storage\StorageDisks;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:storage:check',
    description: 'Check that storage disks can be written, read and deleted from',
    help: <<<'HELP'
        Writes a small file to each disk (or the ones you name), reads it back and deletes it:

          <info>%command.full_name%</info>
          <info>%command.full_name% acme-gallery</info>

        Use it after configuring a disk, e.g. on S3, and in deployment checks.
        HELP,
)]
final readonly class CheckDisksCommand
{
    public function __construct(
        private StorageDisks $disks,
    ) {
    }

    /**
     * @param list<string> $names
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The disks to check; all when none are given', name: 'disks')]
        array $names = [],
    ): int {
        $failed = false;

        foreach ([] === $names ? $this->disks->names() : $names as $name) {
            try {
                $disk = $this->disks->get($name);
                $probe = '_quire/check-'.bin2hex(random_bytes(8)).'.txt';
                $contents = 'Quire storage check '.date(\DATE_ATOM);
                $disk->files->write($probe, $contents);
                $read = $disk->files->read($probe);
                $disk->files->delete($probe);

                if ($read !== $contents) {
                    throw new \RuntimeException('The file read back differs from the one written.');
                }

                $io->writeln(\sprintf('<info>✔</info> %s', $name));
            } catch (\Throwable $e) {
                $failed = true;
                $io->writeln(\sprintf('<error>✘</error> %s: %s', $name, $e->getMessage()));
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
