<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\ContentRepository;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quire:content:list', description: 'List the most recently created content items')]
final readonly class ListContentCommand
{
    public function __construct(
        private ContentRepository $repository,
        private ContentTypeRegistry $types,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only items of this type')]
        ?string $type = null,
        #[Option(description: 'Only items with this status')]
        ?string $status = null,
        #[Option(description: 'List the items in the trash instead')]
        bool $trashed = false,
        #[Option(description: 'How many items to list')]
        int $limit = 20,
    ): int {
        return ConsoleErrors::handle($io, fn (): int => $this->list($io, $type, $status, $trashed, $limit));
    }

    private function list(SymfonyStyle $io, ?string $type, ?string $status, bool $trashed, int $limit): int
    {
        // Editors see everything: drafts and items hidden from visitors included.
        $query = $this->repository->query(...null === $type ? array_keys($this->types->all()) : [$type])
            ->withoutVisibilityScope()
            ->orderBy('created_at', 'desc')
            ->limit(max(1, $limit));

        if ($trashed) {
            $query = $query->onlyTrashed();
        }

        if (null !== $status) {
            $query = $query->status($status);
        }

        $rows = [];

        foreach ($query->get() as $content) {
            $rows[] = [$content->id, $content->type, $content->status, $content->title, $content->slug, $content->locale, $content->version, $content->updatedAt->format('Y-m-d H:i')];
        }

        if ([] === $rows) {
            $io->note($trashed ? 'The trash is empty.' : 'No content items found.');

            return Command::SUCCESS;
        }

        $io->table(['ID', 'Type', 'Status', 'Title', 'Slug', 'Locale', 'Version', 'Updated'], $rows);

        return Command::SUCCESS;
    }
}
