<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:delete',
    description: 'Move a content item to the trash, or delete it permanently',
    help: <<<'HELP'
        Moves the item to the trash, from where <info>quire:content:restore</info> brings it back:

          <info>%command.full_name% 0192b6a0-…</info>

        <comment>--purge</comment> deletes it permanently, with its revisions and the data extensions keep about it:

          <info>%command.full_name% 0192b6a0-… --purge</info>
        HELP,
)]
final readonly class DeleteContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Option(description: 'Delete the item permanently instead of moving it to the trash')]
        bool $purge = false,
        #[Option(description: 'Skip the confirmation for --purge')]
        bool $force = false,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $purge, $force): int {
            $content = $this->repository->get($id);

            if (!$purge) {
                $this->writer->trash($content->id, $content->version);
                $io->success(\sprintf('Moved "%s" to the trash.', $content->title));

                return Command::SUCCESS;
            }

            if (!$force && !$io->confirm(\sprintf('Delete "%s" permanently? This can\'t be undone.', $content->title), false)) {
                $io->note('Nothing was deleted.');

                return Command::FAILURE;
            }

            if (!$content->isTrashed()) {
                $content = $this->writer->trash($content->id, $content->version);
            }

            $this->writer->purge($content->id);
            $io->success(\sprintf('Deleted "%s" permanently.', $content->title));

            return Command::SUCCESS;
        });
    }
}
