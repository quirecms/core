<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Quire\Content\ContentRepository;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Permalink\Redirects;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:redirect:list',
    description: 'List the redirects added by hand',
    help: <<<'HELP'
        Lists the redirects added with <info>quire:redirect:add</info> or <info>quire:redirect:import</info>.
        Redirects from the earlier URLs of content items aren't listed: Quire keeps those by itself.
        HELP,
)]
final readonly class ListRedirectsCommand
{
    public function __construct(
        private Redirects $redirects,
        private ContentRepository $content,
        private Permalinks $permalinks,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->redirects->all() as $redirect) {
            $item = null === $redirect->targetContentId ? null : $this->content->find($redirect->targetContentId);
            $target = $redirect->targetUrl ?? ($item instanceof \Quire\Content\Content ? \sprintf('%s (%s "%s")', $this->permalinks->path($item), $item->type, $item->title) : '(missing item)');
            $rows[] = [$redirect->source, $target, $redirect->status];
        }

        if ([] === $rows) {
            $io->note('No redirects were added. Add one with "bin/console quire:redirect:add <from> <to>".');

            return Command::SUCCESS;
        }

        $io->table(['From', 'To', 'Status'], $rows);

        return Command::SUCCESS;
    }
}
