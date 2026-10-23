<?php

declare(strict_types=1);

namespace Quire\Storage\Console;

use Quire\Storage\Internal\GarbageCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:storage:gc',
    description: 'Delete stored files that nothing uses any more',
    help: <<<'HELP'
        Deletes stored objects that no table references any more, and files on the disks that no object
        records (e.g. from uploads whose transaction was rolled back). Both must be older than the grace
        period, so uploads in progress are safe:

          <info>%command.full_name% --dry-run</info>   (list what would be deleted)
          <info>%command.full_name% --grace="7 days"</info>

        Run it regularly, e.g. daily from cron. Keep the grace period longer than the time between a
        database backup and its oldest restore you'd still want to work, or restore the files too.
        HELP,
)]
final readonly class CollectGarbageCommand
{
    public function __construct(
        private GarbageCollector $collector,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only delete what has been unused for at least this long')]
        string $grace = '1 day',
        #[Option(description: 'List what would be deleted, and delete nothing')]
        bool $dryRun = false,
    ): int {
        try {
            $before = new \DateTimeImmutable('-'.ltrim($grace, '+-'));
        } catch (\Exception) {
            $io->error(\sprintf('--grace takes a duration, e.g. "1 day" or "12 hours"; "%s" isn\'t one.', $grace));

            return Command::FAILURE;
        }

        if ($before > new \DateTimeImmutable()) {
            $io->error('--grace must not be negative.');

            return Command::FAILURE;
        }

        $done = $this->collector->collect($before, $dryRun, $io->isVerbose() || $dryRun ? $io->writeln(...) : null);

        $io->success(\sprintf(
            '%s %d unused object(s) and %d unrecorded file(s), unused since before %s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $done['objects'],
            $done['files'],
            $before->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }
}
