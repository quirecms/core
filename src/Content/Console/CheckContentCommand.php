<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\Check\CheckResult;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Level;
use Quire\Content\ContentRepository;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:content:check',
    description: 'Run the content checks on published content, e.g. in CI or every night, and report what they find',
    help: <<<'HELP'
        <info>%command.full_name%</info>                      published and scheduled items
        <info>%command.full_name% --type=post --status=draft</info>
        <info>%command.full_name% --id=0192b6a0-…</info>
        <info>%command.full_name% --strict</info>             warnings fail it too
        <info>%command.full_name% --list</info>               the checks, and their levels

        It exits with 1 when a check finds an error (with --strict, a warning too). Change the level of a
        check with the setting "quire.content.check_levels", e.g. {"core.alt-text": "error"}.
        HELP,
)]
final readonly class CheckContentCommand
{
    private const int BATCH = 100;

    public function __construct(
        private ContentChecker $checker,
        private ContentRepository $content,
        private ContentTypeRegistry $types,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only items of this type')]
        ?string $type = null,
        #[Option(description: 'Only items with this status; published and scheduled ones by default')]
        ?string $status = null,
        #[Option(description: 'Only this item')]
        ?string $id = null,
        #[Option(description: 'Fail on warnings too')]
        bool $strict = false,
        #[Option(description: 'List the checks and their levels instead')]
        bool $list = false,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $type, $status, $id, $strict, $list): int {
            if ($list) {
                $io->table(['Check', 'What it checks', 'Level', 'Types', 'From'], array_map(static fn (array $check): array => [
                    $check['id'], $check['label'], $check['level']->value ?? 'off', null === $check['types'] ? 'all' : implode(', ', $check['types']), $check['owner'],
                ], $this->checker->checks()));

                return Command::SUCCESS;
            }

            $rows = [];
            [$errors, $warnings, $checked] = [0, 0, 0];

            foreach ($this->items($type, $status, $id) as $item) {
                ++$checked;

                foreach ($this->checker->check($item)->results as $result) {
                    Level::Error === $result->level ? ++$errors : ++$warnings;
                    $rows[] = [$item->title, $item->id, $result->level->value, $this->where($result), $result->finding->message];
                }
            }

            if ([] !== $rows) {
                $io->table(['Item', 'ID', 'Level', 'Where', 'Finding'], $rows);
            }

            $summary = \sprintf('Checked %d %s: %d %s, %d %s.', $checked, 1 === $checked ? 'item' : 'items', $errors, 1 === $errors ? 'error' : 'errors', $warnings, 1 === $warnings ? 'warning' : 'warnings');
            $failed = $errors > 0 || ($strict && $warnings > 0);
            $failed ? $io->error($summary) : $io->success($summary);

            return $failed ? Command::FAILURE : Command::SUCCESS;
        });
    }

    /**
     * @return iterable<\Quire\Content\Content>
     */
    private function items(?string $type, ?string $status, ?string $id): iterable
    {
        if (null !== $id) {
            yield $this->content->get($id);

            return;
        }

        $query = $this->content->query(...null === $type ? array_keys($this->types->all()) : [$type])->withoutVisibilityScope();
        $query = null === $status ? $query->status(Status::PUBLISHED, Status::SCHEDULED) : $query->status($status);

        for ($page = 1;; ++$page) {
            $batch = $query->orderBy('created_at')->paginate($page, self::BATCH);

            yield from $batch->items;

            if (!$batch->hasNextPage()) {
                return;
            }
        }
    }

    private function where(CheckResult $result): string
    {
        $property = $result->finding->property ?? 'body';

        return null === $result->finding->line ? $property : \sprintf('%s, line %d', $property, $result->finding->line);
    }
}
