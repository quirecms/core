<?php

declare(strict_types=1);

namespace Quire\Content\Check;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Internal\Row;

/**
 * The content checks' findings, stored for each item, so lists can show and filter by them (see
 * {@see \Quire\Content\Query\ContentQuery::withIssues()}) without checking every item they show.
 *
 * Items are checked when they're created or saved, when an item they may link to changes, when the
 * site changes the checks' levels, and every night (the task "quire.recheck-content"). Findings are
 * stored without their level: the site's levels apply when they're read, and checks it turned off, or
 * that are no longer there, don't count.
 *
 * @api
 */
final readonly class CheckResults
{
    public function __construct(
        private Connection $connection,
        private ContentChecker $checker,
        private ContentRepository $content,
    ) {
    }

    /**
     * Checks the item and stores what the checks find, in place of what they found before.
     */
    public function refresh(Content $item): CheckReport
    {
        $report = $this->checker->check($item);

        $this->connection->transactional(function () use ($item, $report): void {
            $this->connection->executeStatement('DELETE FROM quire.content_check_result WHERE content_id = ?', [$item->id]);

            foreach ($report->results as $result) {
                $this->connection->insert('quire.content_check_result', [
                    'content_id' => $item->id,
                    'check_id' => $result->check,
                    'message' => $result->finding->message,
                    'property' => $result->finding->property,
                    'line' => $result->finding->line,
                ]);
            }

            $this->connection->executeStatement(
                'INSERT INTO quire.content_check_run (content_id, content_version) VALUES (?, ?)
                 ON CONFLICT (content_id) DO UPDATE SET content_version = EXCLUDED.content_version, checked_at = statement_timestamp()',
                [$item->id, $item->version],
            );
        });

        return $report;
    }

    /**
     * What the checks found in the item when it was last checked.
     */
    public function for(Content|string $item): CheckReport
    {
        $id = $item instanceof Content ? $item->id : $item;

        return $this->forItems([$id])[$id];
    }

    /**
     * What the checks found in each of these items, e.g. a page of a list, in one query. Items that weren't
     * checked yet have an empty report; {@see checkedVersions()} tells which were.
     *
     * @param list<string> $ids
     *
     * @return array<string, CheckReport> By item ID, in the order given
     */
    public function forItems(array $ids): array
    {
        $checks = [];

        foreach ($this->checker->checks() as $check) {
            if (null !== $check['level']) {
                $checks[$check['id']] = $check;
            }
        }

        $results = array_fill_keys($ids, []);
        $rows = [] === $ids || [] === $checks ? [] : $this->connection->fetchAllAssociative(
            'SELECT content_id, check_id, message, property, line FROM quire.content_check_result WHERE content_id IN (?) AND check_id IN (?) ORDER BY id',
            [$ids, array_keys($checks)],
            [ArrayParameterType::STRING, ArrayParameterType::STRING],
        );

        foreach ($rows as $columns) {
            $row = new Row($columns);
            $check = $checks[$row->string('check_id')];
            $results[$row->string('content_id')][] = new CheckResult($check['id'], $check['label'], $check['level'], new Finding($row->string('message'), $row->nullableString('property'), \is_int($columns['line']) ? $columns['line'] : null));
        }

        return array_map(static function (array $results): CheckReport {
            // Errors first, as the checker has them.
            usort($results, static fn (CheckResult $a, CheckResult $b): int => (Level::Error === $a->level ? 0 : 1) <=> (Level::Error === $b->level ? 0 : 1));

            return new CheckReport($results);
        }, $results);
    }

    /**
     * Which version of each item was last checked; items never checked are left out.
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function checkedVersions(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $versions = [];

        foreach ($this->connection->fetchAllAssociative('SELECT content_id, content_version FROM quire.content_check_run WHERE content_id IN (?)', [$ids], [ArrayParameterType::STRING]) as $row) {
            $row = new Row($row);
            $versions[$row->string('content_id')] = $row->int('content_version');
        }

        return $versions;
    }

    /**
     * Checks items again, a batch at a time, in the order of their IDs: all of them, or only those that
     * changed since they were last checked. Items in the trash aren't checked.
     *
     * @param string|null $after Start after this ID, to go on where an earlier call stopped
     *
     * @return array{checked: int, last: ?string} How many were checked, and the last one's ID; null when
     *                                            there are no more
     */
    public function refreshAll(bool $onlyChanged = false, ?string $after = null, int $batch = 200): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT c.id FROM quire.content c LEFT JOIN quire.content_check_run r ON r.content_id = c.id
             WHERE c.deleted_at IS NULL AND (CAST(? AS uuid) IS NULL OR c.id > CAST(? AS uuid))'.($onlyChanged ? ' AND (r.content_version IS NULL OR r.content_version <> c.version)' : '').'
             ORDER BY c.id LIMIT ?',
            [$after, $after, max(1, $batch)],
        );

        foreach ($ids as $id) {
            $item = \is_string($id) ? $this->content->find($id) : null;

            if ($item instanceof Content) {
                $this->refresh($item);
            }
        }

        $last = array_last($ids);

        return ['checked' => \count($ids), 'last' => \count($ids) < $batch || !\is_string($last) ? null : $last];
    }

    /**
     * Checks again the items that may link to an item, e.g. after it was unpublished, moved to the trash
     * or given another slug: those whose text has one of its slugs.
     *
     * @param list<string> $slugs
     *
     * @return int How many were checked
     */
    public function refreshLinkingTo(array $slugs, ?string $except = null): int
    {
        $slugs = array_values(array_filter(array_unique($slugs), static fn (string $slug): bool => '' !== $slug));

        if ([] === $slugs) {
            return 0;
        }

        // A link's path ends with the slug, or has it between slashes; LIKE finds a few more, which is fine.
        $ids = $this->connection->fetchFirstColumn(
            \sprintf('SELECT id FROM quire.content WHERE deleted_at IS NULL AND id IS DISTINCT FROM CAST(? AS uuid) AND (%s)', implode(' OR ', array_fill(0, \count($slugs), "body LIKE '%/' || ? || '%'"))),
            [$except, ...array_map(static fn (string $slug): string => addcslashes($slug, '%_\\'), $slugs)],
        );

        foreach ($ids as $id) {
            $item = \is_string($id) ? $this->content->find($id) : null;

            if ($item instanceof Content) {
                $this->refresh($item);
            }
        }

        return \count($ids);
    }
}
