<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Workflow\ContentWorkflow;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:transition',
    description: 'Publish, schedule, unpublish or archive a content item',
    help: <<<'HELP'
        Moves an item to another status:

          <info>%command.full_name% 0192b6a0-… publish</info>
          <info>%command.full_name% 0192b6a0-… schedule --at "2026-12-24 09:00"</info>
          <info>%command.full_name% 0192b6a0-… unpublish</info>

        Without a transition, it lists those that are possible now, including those extensions add.
        HELP,
)]
final readonly class TransitionContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentWorkflow $workflow,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Argument(description: 'The transition, e.g. publish, schedule, unpublish or archive')]
        ?string $transition = null,
        #[Option(description: 'The publication date, for publish (in the past) and schedule (in the future)')]
        ?string $at = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $transition, $at): int {
            $content = $this->repository->get($id);

            if (null === $transition) {
                $enabled = $this->workflow->enabledTransitions($content);
                $io->writeln(\sprintf('The item is %s. %s', $content->status, [] === $enabled ? 'No transitions are possible now.' : 'Possible transitions: '.implode(', ', $enabled).'.'));

                return Command::SUCCESS;
            }

            $after = $this->writer->transition($content->id, $content->version, $transition, null === $at ? null : ConsoleErrors::date($at, '--at'));
            $io->success(\sprintf('"%s" is now %s.', $after->title, $after->status));

            return Command::SUCCESS;
        });
    }
}
