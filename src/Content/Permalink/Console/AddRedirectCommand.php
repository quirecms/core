<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Quire\Content\Permalink\Redirects;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:redirect:add',
    description: 'Redirect a URL to a content item or another URL',
    help: <<<'HELP'
        Redirects a path to another one, e.g. to keep the URLs of a previous site working:

          <info>%command.full_name% /2019/05/hello-world /blog/hello-world</info>
          <info>%command.full_name% /old-shop https://shop.example.com --status=302</info>

        Paths are relative to where Quire is mounted. When the target is the URL of a content item, the
        redirect follows the item if its URL changes later. A redirect only applies when no content
        answers its URL. Content items need no redirects when their own URLs change: Quire keeps those.
        To add many at once, use <info>quire:redirect:import</info>.
        HELP,
)]
final readonly class AddRedirectCommand
{
    public function __construct(
        private RedirectTargets $targets,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The path to redirect, e.g. /2019/05/hello-world')]
        string $from,
        #[Argument(description: 'Where to: a path such as /blog/hello-world, or a URL')]
        string $to,
        #[Option(description: 'HTTP status: 301 or 308 for permanent redirects, 302 or 307 for temporary ones')]
        int $status = 301,
    ): int {
        try {
            $this->targets->add($from, $to, $status);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s now redirects to %s.', Redirects::normalize($from), $to));

        return Command::SUCCESS;
    }
}
