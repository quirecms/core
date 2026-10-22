<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Quire\Content\Permalink\Redirects;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'quire:redirect:remove', description: 'Remove a redirect added by hand')]
final readonly class RemoveRedirectCommand
{
    public function __construct(
        private Redirects $redirects,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The redirected path, e.g. /2019/05/hello-world')]
        string $from,
    ): int {
        if (!$this->redirects->remove($from)) {
            $io->error(\sprintf('No redirect from %s exists.', Redirects::normalize($from)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Removed the redirect from %s.', Redirects::normalize($from)));

        return Command::SUCCESS;
    }
}
