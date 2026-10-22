<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Quire\Content\Permalink\Internal\PermalinkSettingsValidator;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:permalink:list',
    description: 'Show the URLs of each content type and the homepage',
    help: <<<'HELP'
        Shows each content type's URL pattern and list, in the order URLs are matched, and what the
        homepage shows. Change them with <info>quire:settings:set</info>:

          <info>bin/console quire:settings:set quire.permalinks.post "/{year}/{month}/{slug}"</info>
          <info>bin/console quire:settings:set quire.listings.post /news</info>
          <info>bin/console quire:settings:set site.homepage page</info>

        Old URLs keep working after a change: they redirect to the new ones.
        HELP,
)]
final readonly class ListPermalinksCommand
{
    public function __construct(
        private Permalinks $permalinks,
        private ContentTypeRegistry $types,
        private Settings $settings,
        private PermalinkSettingsValidator $validator,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->permalinks->patterns() as $type => $pattern) {
            $rows[] = [$type, $pattern->pattern, $this->permalinks->listingPath($type) ?? '–'];
        }

        $io->table(['Type', 'Items', 'List'], $rows);

        $homepage = $this->settings->getString('site.homepage');
        $io->text(\sprintf('Homepage: %s', match ($homepage) {
            'page' => \sprintf('the page "%s"', \is_string($page = $this->settings->get('site.homepage_page')) ? $page : ''),
            'route' => \sprintf('the route "%s"', \is_string($route = $this->settings->get('site.homepage_route')) ? $route : ''),
            default => \sprintf('the list of %s', $this->types->has($type = $this->settings->getString('site.homepage_listing')) ? strtolower($this->types->get($type)->pluralLabel) : '"'.$type.'"'),
        }));
        $io->newLine();

        $collisions = $this->validator->collisions();

        if ([] !== $collisions) {
            $io->warning(['Some URL patterns compete for the same URLs. The first in the table above wins, so the other type\'s items may be unreachable:', ...$collisions]);
        }

        return Command::SUCCESS;
    }
}
