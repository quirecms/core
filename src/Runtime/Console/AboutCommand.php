<?php

declare(strict_types=1);

namespace Quire\Runtime\Console;

use Quire\Runtime\SiteReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:about',
    description: 'Show what runs the site and how it is: versions, extensions, migrations and health',
    help: <<<'HELP'
        Shows the site's Quire, PHP and Symfony versions, its title, language and address, whether
        it's installed, its extensions and theme, its database's migrations, and its health checks
        (those of /_quire/health):

          <info>%command.full_name%</info>

        <comment>--format=json</comment> gives the same as JSON, for tools that manage sites (e.g. Quire
        Server, which also reads its "contract" version). Parts that need the database are null when
        it can't be reached; the health checks then say why.
        HELP,
)]
final readonly class AboutCommand
{
    public function __construct(
        private SiteReport $report,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Output format: "table" or "json"')]
        string $format = 'table',
    ): int {
        if (!\in_array($format, ['table', 'json'], true)) {
            throw new InvalidOptionException(\sprintf('Unknown format "%s". Use "table" or "json".', $format));
        }

        $report = $this->report->report();

        if ('json' === $format) {
            $io->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $unknown = static fn (string|bool|null $value): string => null === $value ? '(unknown)' : (\is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        $site = $report['site'];
        $io->definitionList(
            ['Quire' => $report['quire']['version']],
            ['PHP' => $report['php']],
            ['Symfony' => $report['symfony']],
            ['Environment' => $report['environment']],
            ['Site' => $unknown($site['title'])],
            ['Address' => $unknown($site['url'])],
            ['Language' => $unknown($site['locale'])],
            ['Time zone' => $unknown($site['timezone'])],
            ['Installed' => $unknown($site['installed'])],
            ['Theme' => $unknown($report['theme'])],
            ['Migrations' => null === $report['migrations']['executed'] ? '(unknown)' : \sprintf('%d run, %d pending', $report['migrations']['executed'], $report['migrations']['pending'] ?? 0)],
            ['Server contract' => (string) $report['contract']],
        );

        if ([] !== $report['extensions']) {
            $io->table(['Extension', 'Status', 'Version', 'Type'], array_map(static fn (array $extension): array => [$extension['id'], $extension['status'], $extension['version'], $extension['type']], $report['extensions']));
        }

        $io->table(['Health check', 'Status', 'Message'], array_map(
            static fn (string $name, array $check): array => [$name, $check['status'], $check['message'] ?? ''],
            array_keys($report['health']['checks']),
            $report['health']['checks'],
        ));
        'ok' === $report['health']['status'] ? $io->success('Healthy.') : $io->error('Not healthy: see the checks above.');

        return Command::SUCCESS;
    }
}
