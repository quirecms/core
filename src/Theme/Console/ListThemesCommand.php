<?php

declare(strict_types=1);

namespace Quire\Theme\Console;

use Quire\Theme\Exception\ThemeException;
use Quire\Theme\Themes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:theme:list',
    description: 'List the installed themes and show which one the site uses',
)]
final readonly class ListThemesCommand
{
    public function __construct(
        private Themes $themes,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $installed = $this->themes->installed();
        $active = $this->themes->active()?->id;
        $rows = [];

        foreach ($installed as $theme) {
            try {
                $this->themes->check($theme->id);
                $status = $theme->id === $active ? 'active' : 'available';
            } catch (ThemeException $e) {
                $status = 'can\'t be used: '.$e->getMessage();
            }

            $rows[] = [$theme->id, $theme->title, $theme->parent ?? '', $theme->version, $status];
        }

        if ([] === $rows) {
            $io->writeln('No themes are installed. Install one with "composer require <package>".');
        } else {
            $io->table(['ID', 'Title', 'Inherits from', 'Version', 'Status'], $rows);
        }

        $problem = $this->themes->problem();

        if (null !== $problem) {
            $io->warning(\sprintf('The site should use the theme "%s", but it uses Quire\'s built-in templates instead: %s', $this->themes->selected(), $problem));

            return Command::FAILURE;
        }

        if (null === $active) {
            $io->writeln('The site uses Quire\'s built-in templates. Choose a theme with "bin/console quire:theme:use <id>".');
        } else {
            $io->writeln(\sprintf('The site uses "%s"%s.', $active, \count($this->themes->chain()) > 1 ? ', which inherits from "'.implode('", then "', \array_slice($this->themes->chain(), 1)).'"' : ''));
        }

        return Command::SUCCESS;
    }
}
