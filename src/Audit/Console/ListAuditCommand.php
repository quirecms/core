<?php

declare(strict_types=1);

namespace Quire\Audit\Console;

use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Audit\Origin;
use Quire\Content\Console\ConsoleErrors;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:audit:list',
    description: 'Show the audit log: who did what, and when, newest first',
    help: <<<'HELP'
        <info>%command.full_name% --user ada --since "-7 days"</info>
        <info>%command.full_name% --action content. --subject 0192b6a0-…</info>
        <info>%command.full_name% --format jsonl --limit 0 > audit.jsonl</info>

        --action takes an action, e.g. "user.suspended", or a prefix ending with a dot, e.g. "content.".
        The "jsonl" format prints one JSON object per line, e.g. to keep a copy elsewhere.
        HELP,
)]
final readonly class ListAuditCommand
{
    public function __construct(
        private AuditLog $log,
        private UserRepository $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only what this user did: their ID, email or username')]
        ?string $user = null,
        #[Option(description: 'Only actions about this item, user, role or setting: its ID or key')]
        ?string $subject = null,
        #[Option(description: 'Only this action, or the actions starting with this prefix')]
        ?string $action = null,
        #[Option(description: 'Only actions made on the "web", on the "console", or by a "job"')]
        ?string $origin = null,
        #[Option(description: 'Only actions at or after this time, e.g. "2026-10-01" or "-7 days"')]
        ?string $since = null,
        #[Option(description: 'Only actions before this time')]
        ?string $until = null,
        #[Option(description: 'How many entries to show; 0 for all')]
        int $limit = 50,
        #[Option(description: 'Output format: "table" or "jsonl"')]
        string $format = 'table',
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $user, $subject, $action, $origin, $since, $until, $limit, $format): int {
            if (!\in_array($format, ['table', 'jsonl'], true)) {
                throw new \InvalidArgumentException('--format takes "table" or "jsonl".');
            }

            $actor = null === $user ? null : ($this->users->findByReference($user) ?? throw new \InvalidArgumentException(\sprintf('No user "%s".', $user)));
            $query = new AuditQuery(
                actorId: $actor?->id,
                subjectId: $subject,
                action: $action,
                origin: null === $origin ? null : (Origin::tryFrom($origin) ?? throw new \InvalidArgumentException('--origin takes "web", "console" or "job".')),
                since: null === $since ? null : ConsoleErrors::date($since, '--since'),
                until: null === $until ? null : ConsoleErrors::date($until, '--until'),
            );

            'jsonl' === $format ? $this->jsonLines($io, $query, $limit) : $this->table($io, $query, $limit, $actor);

            return Command::SUCCESS;
        });
    }

    private function table(SymfonyStyle $io, AuditQuery $query, int $limit, ?User $actor): void
    {
        $page = $this->log->find($query, perPage: $limit > 0 ? $limit : 500);

        if (0 === $page->total) {
            $io->writeln($actor instanceof User ? \sprintf('Nothing in the audit log by %s.', $actor->displayName) : 'Nothing in the audit log matches.');

            return;
        }

        $io->table(['When', 'Who', 'Action', 'About', 'Command'], array_map(static fn (AuditEntry $entry): array => [
            $entry->lastAt->format('Y-m-d H:i:s').($entry->repeats > 1 ? \sprintf(' (%d×)', $entry->repeats) : ''),
            ($entry->actorName ?? '–').' ('.($entry->via ?? $entry->origin->value).')',
            $entry->action,
            null === $entry->subjectType ? '' : $entry->subjectType.': '.($entry->subjectLabel ?? $entry->subjectId),
            $entry->command ?? '',
        ], $page->entries));

        if ($page->total > \count($page)) {
            $io->writeln(\sprintf('Showing the newest %d of %d. Use --limit or narrow it down.', \count($page), $page->total));
        }
    }

    /**
     * Pages through all entries, so a full export doesn't load them at once.
     */
    private function jsonLines(SymfonyStyle $io, AuditQuery $query, int $limit): void
    {
        $written = 0;
        // New entries would shift the pages.
        $query = new AuditQuery($query->actorId, $query->subjectType, $query->subjectId, $query->action, $query->origin, $query->correlationId, $query->since, $query->until ?? new \DateTimeImmutable());

        for ($number = 1;; ++$number) {
            $page = $this->log->find($query, $number, 500);

            foreach ($page->entries as $entry) {
                if ($limit > 0 && $written >= $limit) {
                    return;
                }

                $io->writeln(json_encode([
                    'id' => $entry->id,
                    'occurred_at' => $entry->occurredAt->format(\DATE_ATOM),
                    'last_at' => $entry->lastAt->format(\DATE_ATOM),
                    'repeats' => $entry->repeats,
                    'action' => $entry->action,
                    'actor_id' => $entry->actorId,
                    'actor_name' => $entry->actorName,
                    'origin' => $entry->origin->value,
                    'via' => $entry->via,
                    'subject_type' => $entry->subjectType,
                    'subject_id' => $entry->subjectId,
                    'subject_label' => $entry->subjectLabel,
                    'details' => (object) $entry->details,
                    'command' => $entry->command,
                    'correlation_id' => $entry->correlationId,
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), SymfonyStyle::OUTPUT_RAW);
                ++$written;
            }

            if (!$page->hasNextPage()) {
                return;
            }
        }
    }
}
