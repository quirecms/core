<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:create',
    description: 'Create a content item',
    help: <<<'HELP'
        Creates a draft, or publishes it right away with <comment>--publish</comment>:

          <info>%command.full_name% post "Hello world" --body "My *first* post." --publish</info>
          <info>%command.full_name% page "Team" --parent 0192b6a0-… --body-file team.md</info>
          <info>%command.full_name% post "Launch" --body-file=- --schedule "2026-12-24 09:00" < launch.md</info>

        The slug is generated from the title unless you pass <comment>--slug</comment>.
        HELP,
)]
final readonly class CreateContentCommand
{
    public function __construct(
        private ContentWriter $writer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The content type, e.g. post or page')]
        string $type,
        #[Argument(description: 'The title')]
        string $title,
        #[Option(description: 'The body text')]
        ?string $body = null,
        #[Option(description: 'Read the body from a file (--body-file=- reads standard input)')]
        ?string $bodyFile = null,
        #[Option(description: 'The body format')]
        string $format = 'markdown',
        #[Option(description: 'The slug used in the URL; generated from the title by default')]
        ?string $slug = null,
        #[Option(description: 'A short summary, for types with excerpts')]
        ?string $excerpt = null,
        #[Option(description: 'The language tag, e.g. "de"; defaults to the site language')]
        ?string $locale = null,
        #[Option(description: 'ID of the parent item, for hierarchical types')]
        ?string $parent = null,
        #[Option(description: 'Fields as a JSON object')]
        ?string $fields = null,
        #[Option(description: 'ID of the item this one translates')]
        ?string $translationOf = null,
        #[Option(description: 'A note for the first revision')]
        ?string $message = null,
        #[Option(description: 'Publish the item right away')]
        bool $publish = false,
        #[Option(description: 'Publish the item at a future date, e.g. "2026-12-24 09:00"')]
        ?string $schedule = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $type, $title, $body, $bodyFile, $format, $slug, $excerpt, $locale, $parent, $fields, $translationOf, $message, $publish, $schedule): int {
            if ($publish && null !== $schedule) {
                throw new \InvalidArgumentException('Pass either --publish or --schedule, not both.');
            }

            $at = null === $schedule ? null : ConsoleErrors::date($schedule, '--schedule');

            $content = $this->writer->create(new CreateContent(
                type: $type,
                title: $title,
                body: ConsoleErrors::body($body, $bodyFile) ?? '',
                bodyFormat: $format,
                slug: $slug,
                locale: $locale,
                excerpt: $excerpt,
                fields: null === $fields ? [] : ConsoleErrors::fields($fields),
                parentId: $parent,
                translationOf: $translationOf,
                revisionMessage: $message,
            ));

            if ($publish || $at instanceof \DateTimeImmutable) {
                $content = $this->writer->transition($content->id, $content->version, $publish ? Status::PUBLISH : Status::SCHEDULE, $at);
            }

            $io->success(\sprintf('Created %s "%s" (%s) as %s, with ID %s.', $content->type, $content->title, $content->slug, $content->status, $content->id));

            return Command::SUCCESS;
        });
    }
}
