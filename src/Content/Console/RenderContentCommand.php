<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRenderer;
use Quire\Content\ContentRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:render',
    description: 'Print the HTML of a content item\'s body',
    help: <<<'HELP'
        Prints the body as visitors get it: formatted, sanitised, with shortcodes rendered.

          <info>%command.full_name% 0192b6a0-…</info>
          <info>%command.full_name% 0192b6a0-… --teaser</info>     (the part before <!--more-->)
          <info>%command.full_name% 0192b6a0-… --excerpt</info>    (the plain-text summary)
        HELP,
)]
final readonly class RenderContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentRenderer $renderer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Option(description: 'The formatter profile, e.g. "comment"')]
        string $formatterProfile = 'content',
        #[Option(description: 'Print the teaser instead of the whole body')]
        bool $teaser = false,
        #[Option(description: 'Print the plain-text excerpt instead')]
        bool $excerpt = false,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $formatterProfile, $teaser, $excerpt): int {
            $content = $this->repository->get($id);

            $io->writeln(match (true) {
                $excerpt => $this->renderer->excerpt($content, profile: $formatterProfile),
                $teaser => $this->renderer->teaser($content, $formatterProfile) ?? $this->renderer->body($content, $formatterProfile),
                default => $this->renderer->body($content, $formatterProfile),
            }, SymfonyStyle::OUTPUT_RAW);

            return Command::SUCCESS;
        });
    }
}
