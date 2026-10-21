<?php

declare(strict_types=1);

namespace Quire\Extension\Console;

use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:extension:list',
    description: 'List the Quire extensions and themes installed through Composer',
    help: <<<'HELP'
        Lists every installed Composer package of type <comment>quire-extension</comment> or <comment>quire-theme</comment>.

        Use <comment>--format=json</comment> for machine-readable output.
        HELP,
)]
final readonly class ListExtensionsCommand
{
    public function __construct(
        private InstalledExtensions $extensions,
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

        $manifests = array_values($this->extensions->all());

        return 'json' === $format ? $this->renderJson($io, $manifests) : $this->renderTable($io, $manifests);
    }

    /**
     * @param list<ExtensionManifest> $manifests
     */
    private function renderTable(SymfonyStyle $io, array $manifests): int
    {
        if ([] === $manifests) {
            $io->note('No Quire extensions are installed. Install one with "composer require <package>".');

            return Command::SUCCESS;
        }

        $io->table(
            ['ID', 'Title', 'Package', 'Version', 'Type', 'Requires'],
            array_map(static fn (ExtensionManifest $m): array => [
                $m->id,
                $m->title,
                $m->packageName,
                $m->version,
                $m->type->name,
                implode(', ', $m->requires),
            ], $manifests),
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<ExtensionManifest> $manifests
     */
    private function renderJson(SymfonyStyle $io, array $manifests): int
    {
        $io->writeln(json_encode(array_map(static fn (ExtensionManifest $m): array => [
            'id' => $m->id,
            'package' => $m->packageName,
            'type' => $m->type->value,
            'version' => $m->version,
            'title' => $m->title,
            'description' => $m->description,
            'category' => $m->category,
            'icon' => $m->icon,
            'requires' => $m->requires,
            'optional' => $m->optional,
            'path' => $m->path,
        ], $manifests), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
