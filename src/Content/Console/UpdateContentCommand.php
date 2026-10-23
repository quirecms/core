<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\Command\UpdateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:update',
    description: 'Change a content item',
    help: <<<'HELP'
        Changes only what you pass:

          <info>%command.full_name% 0192b6a0-… --title "Better title" --message "Fix typo"</info>
          <info>%command.full_name% 0192b6a0-… --body-file post.md</info>
          <info>%command.full_name% 0192b6a0-… --parent ""</info>   (move a page to the top level)
          <info>%command.full_name% 0192b6a0-… --author grace</info>

        Changing the title keeps the slug, so published URLs don't change. Pass <comment>--slug</comment> to change it.
        With <comment>--expect-version</comment>, the update fails if someone else saved the item after that version.
        HELP,
)]
final readonly class UpdateContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Option(description: 'The new title')]
        ?string $title = null,
        #[Option(description: 'The new slug')]
        ?string $slug = null,
        #[Option(description: 'The new body text')]
        ?string $body = null,
        #[Option(description: 'Read the new body from a file (--body-file=- reads standard input)')]
        ?string $bodyFile = null,
        #[Option(description: 'The body format')]
        ?string $format = null,
        #[Option(description: 'The new excerpt ("" removes it)')]
        ?string $excerpt = null,
        #[Option(description: 'ID of the new parent ("" for the top level)')]
        ?string $parent = null,
        #[Option(description: 'The position among siblings')]
        ?int $position = null,
        #[Option(description: 'Fields to change, as a JSON object; null removes a field')]
        ?string $fields = null,
        #[Option(description: 'The new author: a user\'s ID, email or username ("" for none)')]
        ?string $author = null,
        #[Option(description: 'A note for the revision')]
        ?string $message = null,
        #[Option(description: 'Fail if the item is no longer at this version')]
        ?int $expectVersion = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $title, $slug, $body, $bodyFile, $format, $excerpt, $parent, $position, $fields, $author, $message, $expectVersion): int {
            $command = new UpdateContent($id, $expectVersion ?? $this->repository->get($id)->version, revisionMessage: $message);
            $newBody = ConsoleErrors::body($body, $bodyFile);

            if (null !== $title) {
                $command->title($title);
            }

            if (null !== $slug) {
                $command->slug($slug);
            }

            if (null !== $newBody) {
                $command->body($newBody, $format);
            } elseif (null !== $format) {
                throw new \InvalidArgumentException('--format applies to a new body: pass --body or --body-file too.');
            }

            if (null !== $excerpt) {
                $command->excerpt('' === $excerpt ? null : $excerpt);
            }

            if (null !== $parent) {
                $command->parent('' === $parent ? null : $parent);
            }

            if (null !== $position) {
                $command->position($position);
            }

            foreach (null === $fields ? [] : ConsoleErrors::fields($fields) as $name => $value) {
                $command->field($name, $value);
            }

            if (null !== $author) {
                $command->author('' === $author ? null : ConsoleErrors::userId($this->users, $author, '--author'));
            }

            if ($command->isEmpty()) {
                throw new \InvalidArgumentException('Pass at least one change, e.g. --title or --body.');
            }

            $content = $this->writer->update($command);
            $io->success(\sprintf('Saved "%s", now at version %d.', $content->title, $content->version));

            return Command::SUCCESS;
        });
    }
}
