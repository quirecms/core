<?php

declare(strict_types=1);

namespace Quire\Runtime\Console;

use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'quire:app:refresh',
    description: 'Rebuild the application on this machine and restart its PHP workers',
    help: <<<'HELP'
        Rebuilds the application (clears and warms the cache) and gracefully restarts this
        machine's PHP workers, so they serve the current set of extensions.

        Quire runs this by itself when it notices that the enabled extensions changed, for example
        on another server. Only one refresh runs at a time on a machine; others exit at once.
        It doesn't run migrations: the machine that enables an extension does that.
        HELP,
)]
final readonly class RefreshCommand
{
    public function __construct(
        private AppRebuilderInterface $rebuilder,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $lockFile = $this->projectDir.'/var/quire-refresh.lock';
        $lock = @fopen($lockFile, 'c');

        if (false === $lock) {
            $io->error(\sprintf('Can\'t open the lock file "%s". Check that var/ is writable.', $lockFile));

            return Command::FAILURE;
        }

        try {
            if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
                $io->note('Another refresh is already running on this machine.');

                return Command::SUCCESS;
            }

            $io->writeln('Rebuilding the application…');
            $this->rebuilder->rebuild(false, $io->write(...));
            $io->success('The application is up to date.');

            return Command::SUCCESS;
        } catch (RebuildFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }
}
