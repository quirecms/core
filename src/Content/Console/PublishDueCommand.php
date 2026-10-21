<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:publish-due',
    description: 'Publish scheduled content whose date has passed',
    help: <<<'HELP'
        Scheduled items become visible on their date without this command. Running it every minute
        or so marks them as published, so extensions that act on publishing (feeds, notifications,
        cache warming) run for them too. Several servers can run it at once.
        HELP,
)]
final readonly class PublishDueCommand
{
    public function __construct(
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The most items to publish in one run')]
        int $limit = 100,
    ): int {
        $published = $this->writer->publishDue(max(1, $limit));

        foreach ($published as $content) {
            $io->writeln(\sprintf('Published "%s" (%s).', $content->title, $content->id), SymfonyStyle::VERBOSITY_VERBOSE);
        }

        $io->writeln(\sprintf('Published %d scheduled item%s.', \count($published), 1 === \count($published) ? '' : 's'));

        return Command::SUCCESS;
    }
}
