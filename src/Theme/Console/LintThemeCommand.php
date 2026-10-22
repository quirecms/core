<?php

declare(strict_types=1);

namespace Quire\Theme\Console;

use Quire\Theme\Internal\ThemeLinter;
use Quire\Theme\Themes;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:theme:lint',
    description: 'Check a theme: its templates compile, render every standard slot and link existing assets',
    help: <<<'HELP'
        Checks the site's theme, or the installed theme you name, without rendering a page:

          <info>%command.full_name%</info>
          <info>%command.full_name% acme-dark</info>

        It checks the templates the site would use with the theme (the theme's, its parents', and Quire's
        built-in ones where neither has one): they compile, they render every standard slot, so extensions
        can add their parts, and the files they link with quire_asset() exist. It exits with an error code
        when something is wrong, so it can run in CI.
        HELP,
)]
final readonly class LintThemeCommand
{
    public function __construct(
        private Themes $themes,
        private ThemeLinter $linter,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the theme; the site\'s theme by default')]
        ?string $id = null,
    ): int {
        $id ??= $this->themes->active()->id ?? $this->themes->selected();

        if (null === $id) {
            $io->error('The site has no theme. Name the theme to check, e.g. "bin/console quire:theme:lint acme-dark".');

            return Command::FAILURE;
        }

        $result = $this->linter->lint($id);

        foreach ($result['warnings'] as $warning) {
            $io->warning($warning);
        }

        if ([] !== $result['errors']) {
            $io->error(\sprintf('The theme "%s" has %d problem%s.', $id, \count($result['errors']), 1 === \count($result['errors']) ? '' : 's'));
            $io->listing($result['errors']);

            return Command::FAILURE;
        }

        $io->success(\sprintf('The theme "%s" is fine: %d templates, every standard slot rendered.', $id, $result['templates']));

        return Command::SUCCESS;
    }
}
