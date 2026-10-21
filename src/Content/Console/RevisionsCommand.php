<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\RevisionRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:revisions',
    description: 'List the revisions of a content item, or restore one',
    help: <<<'HELP'
        <info>%command.full_name% 0192b6a0-…</info>
        <info>%command.full_name% 0192b6a0-… --restore 0192b6a1-…</info>

        Restoring saves the revision's text and fields as a new revision, so nothing is lost.
        HELP,
)]
final readonly class RevisionsCommand
{
    public function __construct(
        private ContentRepository $repository,
        private RevisionRepository $revisions,
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Option(description: 'ID of the revision to restore')]
        ?string $restore = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $restore): int {
            $content = $this->repository->get($id);

            if (null !== $restore) {
                $after = $this->writer->restoreRevision($content->id, $restore, $content->version);
                $io->success(\sprintf('Restored the revision. "%s" is now at version %d.', $after->title, $after->version));

                return Command::SUCCESS;
            }

            $rows = [];

            foreach ($this->revisions->forContent($content->id) as $revision) {
                $rows[] = [$revision->id, $revision->contentVersion, $revision->createdAt->format('Y-m-d H:i'), $revision->title, $revision->message ?? ''];
            }

            if ([] === $rows) {
                $io->note('The item has no revisions.');

                return Command::SUCCESS;
            }

            $io->table(['Revision', 'Version', 'Saved', 'Title', 'Message'], $rows);

            return Command::SUCCESS;
        });
    }
}
