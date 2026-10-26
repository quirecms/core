<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\Verdict;
use Quire\User\Exception\UserNotFoundException;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:pending',
    description: 'List the pending revisions of a content item (changes not published yet); show, submit, review, publish, schedule or discard one',
    help: <<<'HELP'
        <info>%command.full_name% 0192b6a0-…</info>
        <info>%command.full_name% 0192b6a0-… --show 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… --submit 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… --approve 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… --request-changes 0192b6a1-… --message "Please add a source."</info>
        <info>%command.full_name% 0192b6a0-… --comment 0192b6a1-… --message "Looks good so far."</info>
        <info>%command.full_name% 0192b6a0-… --publish 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… --discard 0192b6a1-…</info>
        <info>%command.full_name% 0192b6a0-… --schedule 0192b6a1-… --at "2026-12-24 09:00 Europe/Berlin"</info>
        <info>%command.full_name% 0192b6a0-… --schedule 0192b6a1-… --at none</info>

        Showing a pending revision prints what it changes, as a diff, and whether it merges with the
        live item. Submitting asks for a review; reviewers approve, request changes or comment, as the
        user of --as. Publishing merges it into the item. Scheduled changes are published at their time by
        the task quire.publish-due, with the same rules; "--at none" clears the time.
        HELP,
)]
final readonly class PendingRevisionsCommand
{
    public function __construct(
        private ContentRepository $content,
        private PendingRevisionRepository $repository,
        private PendingRevisionWriter $pending,
        private ContentWriter $writer,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
        #[Option(description: 'ID of a pending revision to show')]
        ?string $show = null,
        #[Option(description: 'ID of a pending revision to publish')]
        ?string $publish = null,
        #[Option(description: 'ID of a pending revision to delete without publishing it')]
        ?string $discard = null,
        #[Option(description: 'ID of a pending revision to publish at the time of --at')]
        ?string $schedule = null,
        #[Option(description: 'When to publish it, with a time zone or offset, e.g. "2026-12-24 09:00 Europe/Berlin"; "none" clears it')]
        ?string $at = null,
        #[Option(description: 'ID of a pending revision to ask for a review of')]
        ?string $submit = null,
        #[Option(description: 'ID of a pending revision to approve')]
        ?string $approve = null,
        #[Option(description: 'ID of a pending revision to ask for changes to')]
        ?string $requestChanges = null,
        #[Option(description: 'ID of a pending revision to comment on (with --message)')]
        ?string $comment = null,
        #[Option(description: 'What the review says')]
        ?string $message = null,
        #[Option(description: 'Who reviews or submits: a user\'s ID, email or username; approving needs someone other than the author')]
        ?string $as = null,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id, $show, $publish, $discard, $schedule, $at, $submit, $approve, $requestChanges, $comment, $message, $as): int {
            $content = $this->content->get($id);
            $actor = null === $as ? null : ($this->users->findByReference($as) ?? throw UserNotFoundException::for($as))->id;

            if (\count(array_filter([$show, $publish, $discard, $schedule, $submit, $approve, $requestChanges, $comment], static fn (?string $option): bool => null !== $option)) > 1) {
                throw new \InvalidArgumentException('Pass one of --show, --submit, --approve, --request-changes, --comment, --publish, --schedule and --discard.');
            }

            if (null !== $submit) {
                $pending = $this->ofItem($submit, $content->id);
                $this->pending->submit($pending->id, $pending->version, $actor ?? $pending->authorId);
                $io->success('The changes are waiting for a review.');

                return Command::SUCCESS;
            }

            $verdict = match (true) {
                null !== $approve => [Verdict::Approve, $approve],
                null !== $requestChanges => [Verdict::RequestChanges, $requestChanges],
                null !== $comment => [Verdict::Comment, $comment],
                default => null,
            };

            if (null !== $verdict) {
                [$kind, $pendingId] = $verdict;
                $pending = $this->ofItem($pendingId, $content->id);
                $this->pending->review($pending->id, $pending->version, $kind, $actor, $message);
                $io->success(match ($kind) {
                    Verdict::Approve => 'Approved the changes.',
                    Verdict::RequestChanges => 'Asked for changes.',
                    Verdict::Comment => 'Commented on the changes.',
                });

                return Command::SUCCESS;
            }

            if (null !== $schedule) {
                $pending = $this->ofItem($schedule, $content->id);
                $time = match (true) {
                    null === $at => throw new \InvalidArgumentException('Pass --at with the time to publish the changes, or "none".'),
                    'none' === $at => null,
                    default => $this->time($at),
                };
                $pending = $this->pending->schedule($pending->id, $pending->version, $time);
                $io->success($pending->publishAt instanceof \DateTimeImmutable ? \sprintf('The changes will be published at %s.', $pending->publishAt->format('Y-m-d H:i T')) : 'The changes have no time to be published any more.');

                return Command::SUCCESS;
            }

            if (null !== $publish) {
                $pending = $this->ofItem($publish, $content->id);
                $after = $this->writer->publishPendingRevision($pending->id, $pending->version);
                $io->success(\sprintf('Published the changes. "%s" is now at version %d.', $after->title, $after->version));

                return Command::SUCCESS;
            }

            if (null !== $discard) {
                $this->pending->discard($this->ofItem($discard, $content->id)->id);
                $io->success('Discarded the changes.');

                return Command::SUCCESS;
            }

            if (null !== $show) {
                $this->show($io, $this->ofItem($show, $content->id));

                return Command::SUCCESS;
            }

            $rows = array_map(static fn (PendingRevision $pending): array => [
                $pending->id, $pending->state->value, $pending->version, $pending->baseVersion, $pending->updatedAt->format('Y-m-d H:i'),
                $pending->publishAt?->format('Y-m-d H:i T') ?? ($pending->publishProblem ?? ''), $pending->message ?? '',
            ], $this->repository->forContent($content->id));

            if ([] === $rows) {
                $io->note('The item has no pending revisions.');

                return Command::SUCCESS;
            }

            $io->table(['Pending revision', 'State', 'Version', 'Based on', 'Saved', 'Publish at', 'Message'], $rows);

            return Command::SUCCESS;
        });
    }

    private function time(string $at): \DateTimeImmutable
    {
        // A time zone or offset is required: the server's time zone isn't the site's.
        if (1 !== preg_match('/(Z|[+-]\d{2}:?\d{2}|[A-Za-z]+\/[A-Za-z_]+|UTC)$/', trim($at))) {
            throw new \InvalidArgumentException(\sprintf('Give the time with a time zone or offset, e.g. "%s Europe/Berlin" or "%s+01:00".', trim($at), trim($at)));
        }

        try {
            return new \DateTimeImmutable($at);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a time, e.g. "2026-12-24 09:00 Europe/Berlin".', $at));
        }
    }

    private function show(SymfonyStyle $io, PendingRevision $pending): void
    {
        $diff = $pending->diff();
        $io->definitionList(
            ['State' => $pending->state->value],
            ['Based on' => \sprintf('version %d', $pending->baseVersion)],
            ['Message' => $pending->message ?? ''],
            ['Changes' => $diff->isEmpty() ? 'none' : implode(', ', array_keys($diff->changes))],
        );

        foreach ($diff->changes as $property => $change) {
            if ('body' !== $property) {
                $io->writeln(\sprintf('<comment>%s</comment>: %s → %s', $property, OutputFormatter::escape($this->value($change['old'])), OutputFormatter::escape($this->value($change['new']))));
            }
        }

        if ('' !== $diff->unified()) {
            $io->newLine();
            $io->writeln(OutputFormatter::escape($diff->unified()));
        }

        $merge = $this->repository->merge($pending);

        if (!$merge->isClean()) {
            $io->warning(array_merge(['It conflicts with changes published since it was started:'], array_map(static fn (string $property, string $message): string => \sprintf('%s: %s', $property, $message), array_keys($merge->conflicts), $merge->conflicts)));
        }
    }

    private function ofItem(string $pendingId, string $contentId): PendingRevision
    {
        $pending = $this->repository->get($pendingId);

        return $pending->contentId === $contentId ? $pending : throw new \InvalidArgumentException(\sprintf('The pending revision "%s" is for another item.', $pendingId));
    }

    private function value(mixed $value): string
    {
        return null === $value ? '(none)' : json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
