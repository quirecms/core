<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\Revision\Comment;
use Quire\Content\Revision\CommentAnchor;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\User\Exception\UserNotFoundException;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:comments',
    description: 'List the comments on a pending revision (changes not published yet); add one, reply, resolve or reopen a thread',
    help: <<<'HELP'
        <info>%command.full_name% 0192b6a0-… 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… 0192b6a1-… --add "Is this the final list?"</info>
        <info>%command.full_name% 0192b6a0-… 0192b6a1-… --add "Its full name is Northside United." --quote "Northside" --line 12</info>
        <info>%command.full_name% 0192b6a0-… 0192b6a1-… --add "Yes, since August." --reply-to 0192b6a2-…</info>
        <info>%command.full_name% 0192b6a0-… 0192b6a1-… --resolve 0192b6a2-…</info>

        A comment can point at text of the changes: --quote quotes it (on one line) and --line says which
        line of the body it's on, the nearest one with it otherwise; --property points at the title, the
        excerpt or a field instead ("fields.<name>"). As the changes are edited, the text is found again;
        when it's gone, the comment is outdated.
        HELP,
)]
final readonly class CommentsCommand
{
    public function __construct(
        private ContentRepository $content,
        private PendingRevisionRepository $pending,
        private PendingRevisionComments $comments,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Argument(description: 'The pending revision\'s ID')]
        string $pendingRevision,
        #[Option(description: 'A comment to add')]
        ?string $add = null,
        #[Option(description: 'The text it\'s about, on one line')]
        ?string $quote = null,
        #[Option(description: 'The line of the body that text is on')]
        ?int $line = null,
        #[Option(description: 'What it\'s about: body, title, excerpt or fields.<name>')]
        string $property = 'body',
        #[Option(description: 'ID of the thread\'s first comment, to reply to it')]
        ?string $replyTo = null,
        #[Option(description: 'ID of a thread\'s first comment, to mark it resolved')]
        ?string $resolve = null,
        #[Option(description: 'ID of a thread\'s first comment, to open it again')]
        ?string $reopen = null,
        #[Option(description: 'Who writes: a user\'s ID, email or username')]
        ?string $as = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $pendingRevision, $add, $quote, $line, $property, $replyTo, $resolve, $reopen, $as): int {
            $content = $this->content->get($id);
            $pending = $this->pending->get($pendingRevision);
            $actor = null === $as ? null : ($this->users->findByReference($as) ?? throw UserNotFoundException::for($as))->id;

            if ($pending->contentId !== $content->id) {
                throw new \InvalidArgumentException(\sprintf('The pending revision "%s" is for another item.', $pendingRevision));
            }

            if (\count(array_filter([$add, $resolve, $reopen], static fn (?string $option): bool => null !== $option)) > 1) {
                throw new \InvalidArgumentException('Pass one of --add, --resolve and --reopen.');
            }

            if (null !== $add) {
                $comment = $this->comments->add($pending->id, $add, $actor, null === $quote ? null : new CommentAnchor($property, $quote, $line ?? 1), $replyTo);
                $io->success(\sprintf('%s %s.', null === $replyTo ? 'Added the comment' : 'Replied', $comment->id));

                return Command::SUCCESS;
            }

            if (null !== $resolve || null !== $reopen) {
                $comment = $this->comments->get($resolve ?? $reopen);

                if ($comment->pendingRevisionId !== $pending->id) {
                    throw new \InvalidArgumentException('The comment is on other changes.');
                }

                $this->comments->resolve($comment->id, $actor, null !== $resolve);
                $io->success(null !== $resolve ? 'Marked the thread resolved.' : 'Opened the thread again.');

                return Command::SUCCESS;
            }

            $threads = $this->comments->threads($pending);

            if ([] === $threads) {
                $io->note('Nobody has commented on these changes.');

                return Command::SUCCESS;
            }

            foreach ($threads as $thread) {
                $comment = $thread->comment;
                $anchor = $comment->anchor;
                $where = match (true) {
                    null === $anchor => '',
                    $thread->outdated => \sprintf(' on “%s” (outdated)', $anchor->quote),
                    null !== $thread->line => \sprintf(' on “%s”, line %d', $anchor->quote, $thread->line),
                    default => \sprintf(' on “%s” in %s', $anchor->quote, $anchor->property),
                };
                $io->writeln(\sprintf('<comment>%s</comment>%s%s', $comment->id, OutputFormatter::escape($where), $comment->isResolved() ? ' <info>(resolved)</info>' : ''));

                foreach ([$comment, ...$thread->replies] as $entry) {
                    $io->writeln(\sprintf('  %s %s: %s', $entry->createdAt->format('Y-m-d H:i'), $this->name($entry), OutputFormatter::escape($entry->body)));
                }

                $io->newLine();
            }

            return Command::SUCCESS;
        });
    }

    private function name(Comment $comment): string
    {
        return null === $comment->authorId ? '(someone)' : ($this->users->find($comment->authorId)->displayName ?? '(someone)');
    }
}
