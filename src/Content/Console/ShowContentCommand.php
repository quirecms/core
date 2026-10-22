<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Workflow\ContentWorkflow;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quire:content:show', description: 'Show a content item')]
final readonly class ShowContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWorkflow $workflow,
        private Permalinks $permalinks,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The item\'s ID')]
        string $id,
    ): int {
        return ConsoleErrors::handle($io, function () use ($io, $id): int {
            $content = $this->repository->get($id);
            $date = static fn (?\DateTimeImmutable $date): string => $date?->format('Y-m-d H:i:s T') ?? '';

            $io->definitionList(
                ['ID' => $content->id],
                ['Type' => $content->type],
                ['Title' => $content->title],
                ['Slug' => $content->slug],
                ['URL' => $this->permalinks->path($content).($content->isPublic() ? '' : ' (not public)')],
                ['Status' => $content->status.($content->isTrashed() ? ' (in the trash)' : '')],
                ['Transitions' => $content->isTrashed() ? '' : implode(', ', $this->workflow->enabledTransitions($content))],
                ['Locale' => $content->locale],
                ['Translation group' => $content->translationGroupId ?? ''],
                ['Parent' => $content->parentId ?? ''],
                ['Position' => (string) $content->position],
                ['Excerpt' => $content->excerpt ?? ''],
                ['Format' => $content->bodyFormat],
                ['Fields' => [] === $content->fields ? '' : json_encode($content->fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
                ['Version' => (string) $content->version],
                ['Published' => $date($content->publishedAt)],
                ['Created' => $date($content->createdAt)],
                ['Updated' => $date($content->updatedAt)],
            );
            $io->writeln($content->body);

            return Command::SUCCESS;
        });
    }
}
