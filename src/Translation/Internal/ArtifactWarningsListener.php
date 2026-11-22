<?php

declare(strict_types=1);

namespace Quire\Translation\Internal;

use Quire\Extension\InstalledExtensions;
use Quire\Translation\TranslationArtifacts;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Says which language packages are left out, and why, when the site is built from the command line
 * (cache:clear, cache:warmup: a deployment, an update): an ignored one never stops the site, so it's said
 * where whoever builds it looks. quire:about lists them too.
 *
 * Decided again from the packages installed now, not taken from the container the command ran in: without
 * debug, that's the one built before a "composer update", and cache:clear builds the new one beside it.
 *
 * @internal
 */
final readonly class ArtifactWarningsListener
{
    private const array BUILDS = ['cache:clear', 'cache:warmup'];

    public function __construct(
        private InstalledExtensions $installed,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function __invoke(ConsoleTerminateEvent $event): void
    {
        if (!\in_array($event->getCommand()?->getName(), self::BUILDS, true) || 0 !== $event->getExitCode()) {
            return;
        }

        $io = new SymfonyStyle($event->getInput(), $event->getOutput());

        try {
            $statuses = new TranslationArtifacts()->read($this->installed->composerPackages(), $this->installed->vendorComposerDir());
        } catch (\Throwable $e) {
            $io->warning(\sprintf('The language packages couldn\'t be checked (%s): run "bin/console quire:about" to see them.', $e->getMessage()));

            return;
        }

        foreach ($statuses as $status) {
            if (!$status->loaded) {
                $io->warning(\sprintf('The language package "%s" isn\'t loaded: %s', $status->name, $status->reason));
            }
        }
    }
}
