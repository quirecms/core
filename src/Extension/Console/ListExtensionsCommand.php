<?php

declare(strict_types=1);

namespace Quire\Extension\Console;

use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\ResolvedExtensions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'quire:extension:list',
    description: 'List the installed Quire extensions and themes, and whether they run',
    help: <<<'HELP'
        Lists every installed Composer package of type <comment>quire-extension</comment> or
        <comment>quire-theme</comment>, with its status:

          <info>enabled</info>   runs; the number is its position in the boot order
          <info>disabled</info>  installed, not enabled
          <error>broken</error>    enabled, but can't run (the reason is shown)
          <error>missing</error>   enabled, but no longer installed

        Use <comment>--format=json</comment> for machine-readable output.
        HELP,
)]
final readonly class ListExtensionsCommand
{
    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
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

        $manifests = array_values($this->installed->all());
        $resolved = $this->extensions->resolve();

        return 'json' === $format
            ? $this->renderJson($io, $manifests, $resolved)
            : $this->renderTable($io, $manifests, $resolved);
    }

    /**
     * @param list<ExtensionManifest> $manifests
     */
    private function renderTable(SymfonyStyle $io, array $manifests, ResolvedExtensions $resolved): int
    {
        if ([] === $manifests && [] === $resolved->missing) {
            $io->note('No Quire extensions are installed. Install one with "composer require <package>".');

            return Command::SUCCESS;
        }

        $rows = array_map(fn (ExtensionManifest $m): array => [
            $m->id,
            $this->statusLabel($m->id, $resolved),
            $m->title,
            $m->packageName,
            $m->version,
            $m->type->name,
            implode(', ', $m->requires),
        ], $manifests);

        foreach ($resolved->missing as $id) {
            $rows[] = [$id, '<error>missing</error>', '', '', '', '', ''];
        }

        $io->table(['ID', 'Status', 'Title', 'Package', 'Version', 'Type', 'Requires'], $rows);

        foreach ($resolved->broken as $id => $reason) {
            $io->warning(\sprintf('"%s" is enabled but can\'t run: %s.', $id, $reason));
        }

        foreach ($resolved->missing as $id) {
            $io->warning(\sprintf('"%s" is enabled but no longer installed. Reinstall it with Composer, or run "quire:extension:disable %1$s".', $id));
        }

        $readOnlyReason = $this->extensions->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->note($readOnlyReason);
        }

        return Command::SUCCESS;
    }

    private function statusLabel(string $id, ResolvedExtensions $resolved): string
    {
        return match (true) {
            null !== $resolved->position($id) => \sprintf('<info>enabled</info> (%d)', $resolved->position($id)),
            isset($resolved->broken[$id]) => '<error>broken</error>',
            default => 'disabled',
        };
    }

    /**
     * @param list<ExtensionManifest> $manifests
     */
    private function renderJson(SymfonyStyle $io, array $manifests, ResolvedExtensions $resolved): int
    {
        $data = array_map(static fn (ExtensionManifest $m): array => [
            'id' => $m->id,
            'status' => match (true) {
                $resolved->isRunning($m->id) => 'enabled',
                isset($resolved->broken[$m->id]) => 'broken',
                default => 'disabled',
            },
            'position' => $resolved->position($m->id),
            'problem' => $resolved->broken[$m->id] ?? null,
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
        ], $manifests);

        foreach ($resolved->missing as $id) {
            $data[] = ['id' => $id, 'status' => 'missing', 'position' => null, 'problem' => 'enabled, but no longer installed'];
        }

        $io->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
