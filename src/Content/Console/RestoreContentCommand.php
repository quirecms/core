<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quire:content:restore', description: 'Restore a content item from the trash')]
final readonly class RestoreContentCommand
{
    public function __construct(
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id): int {
            $content = $this->writer->restore($id);
            $io->success(\sprintf('Restored "%s" (%s).', $content->title, $content->slug));

            return Command::SUCCESS;
        });
    }
}
