<?php

declare(strict_types=1);

namespace Quire\Theme\Console;

use Quire\Extension\Rebuild\RebuildFailedException;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\ThemeSwitcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:theme:use',
    description: 'Make an installed theme the site\'s theme',
    help: <<<'HELP'
        Makes the theme the site's theme and rebuilds the application. Other servers notice the change and
        rebuild by themselves within a few seconds.

          <info>%command.full_name% acme-dark</info>
          <info>%command.full_name% --none</info>    (back to Quire's built-in templates)

        The theme, the themes it inherits from and the extensions they require must be installed, and the
        extensions enabled. See the installed themes with <info>quire:theme:list</info>.
        HELP,
)]
final readonly class UseThemeCommand
{
    public function __construct(
        private ThemeSwitcher $switcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'ID of the theme (see quire:theme:list)')]
        ?string $id = null,
        #[Option(description: 'Use no theme: Quire\'s built-in templates')]
        bool $none = false,
    ): int {
        if ((null === $id) === !$none) {
            $io->error('Pass the ID of a theme, or --none.');

            return Command::FAILURE;
        }

        try {
            $changed = $this->switcher->use($id, $io->write(...));
        } catch (ThemeException|RebuildFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$changed) {
            $io->success(null === $id ? 'The site already uses Quire\'s built-in templates.' : \sprintf('The site already uses "%s".', $id));

            return Command::SUCCESS;
        }

        $io->success(null === $id ? 'The site uses Quire\'s built-in templates.' : \sprintf('The site uses "%s".', $id));

        return Command::SUCCESS;
    }
}
