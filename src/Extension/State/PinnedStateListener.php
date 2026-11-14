<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\Rebuild\RebuildFailedException;
use Quire\Theme\Themes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Once Quire's migrations ran: records what a pinned site runs, or carries it over when the site stopped
 * pinning (see {@see PinnedState}). They run on every deploy and at installation, so no step is needed
 * for either.
 *
 * @internal
 */
#[AsEventListener(event: ConsoleEvents::TERMINATE)]
final readonly class PinnedStateListener
{
    public const string COMMAND = 'quire:migrations:migrate';

    /** The migrate command's description, which says what this adds */
    public const string DESCRIPTION = 'Run the pending migrations of Quire and its extensions, and keep a pinned site\'s extensions and theme for when it stops pinning';

    /**
     * Says in the migrate command's description and help what it also does here.
     */
    public static function describe(Command $command): void
    {
        $command->setDescription(self::DESCRIPTION);
        $command->setHelp(rtrim($command->getHelp())."\n\n".<<<'HELP'
            <comment>Pinned extensions:</comment> once the migrations ran, Quire also records which extensions and theme
            are pinned in the configuration ("quire.extensions.pinned: true"), and on the first run after the
            site stopped pinning, it enables those extensions and chooses that theme, then forgets the record,
            so the site keeps what it ran. See "Stop pinning" in docs/extensions.md. A dry run does neither.
            HELP);
    }

    public function __construct(
        private PinnedState $pinnedState,
        private ExtensionStateStoreInterface $state,
        private Themes $themes,
        #[Autowire(param: Themes::PINNED_PARAMETER)]
        private bool $pinned,
    ) {
    }

    public function __invoke(ConsoleTerminateEvent $event): void
    {
        $input = $event->getInput();

        if (self::COMMAND !== $event->getCommand()?->getName() || Command::SUCCESS !== $event->getExitCode()
            || ($input->hasOption('dry-run') && false !== $input->getOption('dry-run'))) {
            return;
        }

        if ($this->pinned) {
            $this->pinnedState->record($this->state->enabledIds(), $this->themes->selected());

            return;
        }

        $output = $event->getOutput();

        try {
            $this->pinnedState->carryOver($output->write(...));
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException|RebuildFailedException $e) {
            $output->writeln(\sprintf('<error>What the site ran pinned couldn\'t be carried over: %s</error>', $e->getMessage()));
            $output->writeln('Nothing was changed, unless the rebuild failed. Pin the site again, or enable its extensions with "bin/console quire:extension:enable" and choose its theme with "bin/console quire:theme:use".');
            $event->setExitCode(Command::FAILURE);
        }
    }
}
