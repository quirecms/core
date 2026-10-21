<?php

declare(strict_types=1);

namespace Quire\Extension\Console;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\Rebuild\RebuildFailedException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:extension:enable',
    description: 'Enable one or more installed extensions, including the extensions they require',
    help: <<<'HELP'
        Enables the given extensions. Extensions they require are enabled too; you'll be asked to
        confirm that unless you pass <comment>--no-interaction</comment>.

          <info>%command.full_name% acme-reviews</info>
          <info>%command.full_name% acme-reviews acme-seo --dry-run</info>
        HELP,
)]
final readonly class EnableExtensionCommand
{
    public function __construct(
        private ExtensionManager $extensions,
        private AppRebuilderInterface $rebuilder,
    ) {
    }

    /**
     * @param list<string> $ids
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'IDs of the extensions to enable (see quire:extension:list)')]
        array $ids,
        #[Option(description: 'Show what would be enabled without changing anything')]
        bool $dryRun = false,
    ): int {
        $readOnlyReason = $this->extensions->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->error($readOnlyReason);

            return Command::FAILURE;
        }

        try {
            $plan = $this->extensions->planEnable($ids);
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $plan) {
            $io->success(\sprintf('Already enabled: %s.', implode(', ', $ids)));

            return Command::SUCCESS;
        }

        $planIds = array_map(static fn (ExtensionManifest $m): string => $m->id, $plan);
        $dependencies = array_values(array_diff($planIds, $ids));

        if ([] !== $dependencies) {
            $io->note(\sprintf('Required extensions will be enabled too: %s.', implode(', ', $dependencies)));
        }

        if ($dryRun) {
            $io->writeln('Would enable, in this order:');
            $io->listing($planIds);

            return Command::SUCCESS;
        }

        if ([] !== $dependencies && !$io->confirm('Continue?')) {
            $io->writeln('Nothing was changed.');

            return Command::SUCCESS;
        }

        try {
            $this->extensions->enable($ids);
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('Rebuilding the application and running migrations…');

        try {
            $this->rebuilder->rebuild(true, $io->write(...));
        } catch (RebuildFailedException $e) {
            // Don't leave extensions enabled whose migrations didn't run.
            $this->extensions->disable($planIds);
            $io->error(\sprintf('%s The extensions were disabled again: %s.', $e->getMessage(), implode(', ', $planIds)));
            $this->rebuilder->rebuild(false, $io->write(...));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Enabled: %s.', implode(', ', $planIds)));

        return Command::SUCCESS;
    }
}
