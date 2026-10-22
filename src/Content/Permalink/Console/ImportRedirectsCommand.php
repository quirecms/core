<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:redirect:import',
    description: 'Add redirects from a CSV file',
    help: <<<'HELP'
        Adds the redirects listed in a CSV file, one per line: the old path or URL, the new one, and
        optionally the status. A first line starting with "source" or "from" is skipped as a header.

          /2019/05/hello-world/,/blog/hello-world
          https://old.example.com/about-us/,/about,301

        <info>%command.full_name% redirects.csv</info>
        <info>%command.full_name% - < redirects.csv</info>   (reads standard input)

        Existing redirects with the same source are replaced. Lines that can't be added are reported,
        and the others are still added.
        HELP,
)]
final readonly class ImportRedirectsCommand
{
    public function __construct(
        private RedirectTargets $targets,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The CSV file, or "-" for standard input')]
        string $file,
        #[Option(description: 'Status for lines that don\'t give one')]
        int $status = 301,
    ): int {
        $handle = '-' === $file ? \STDIN : @fopen($file, 'r');

        if (false === $handle) {
            $io->error(\sprintf('Can\'t read "%s".', $file));

            return Command::FAILURE;
        }

        $added = 0;
        $errors = [];

        for ($line = 1; false !== ($row = fgetcsv($handle, escape: '')); ++$line) {
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), $row);

            if ([''] === $cells || (1 === $line && \in_array(strtolower($cells[0]), ['source', 'from'], true))) {
                continue;
            }

            if (\count($cells) < 2 || '' === $cells[0] || '' === $cells[1]) {
                $errors[] = \sprintf('Line %d: expected "from,to" or "from,to,status".', $line);

                continue;
            }

            try {
                $this->targets->add($cells[0], $cells[1], isset($cells[2]) && '' !== $cells[2] ? (int) $cells[2] : $status);
                ++$added;
            } catch (\InvalidArgumentException $e) {
                $errors[] = \sprintf('Line %d (%s): %s', $line, $cells[0], $e->getMessage());
            }
        }

        if (\STDIN !== $handle) {
            fclose($handle);
        }

        if ([] !== $errors) {
            $io->warning($errors);
        }

        $io->success(\sprintf('Added %d redirect(s).', $added));

        return [] === $errors ? Command::SUCCESS : Command::FAILURE;
    }
}
