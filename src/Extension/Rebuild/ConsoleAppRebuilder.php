<?php

declare(strict_types=1);

namespace Quire\Extension\Rebuild;

use Quire\Runtime\PhpBinary;
use Quire\Runtime\WorkerRestarterInterface;
use Symfony\Component\AssetMapper\CompiledAssetMapperConfigReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Rebuilds the application by running console commands in new processes, then restarts this
 * machine's PHP workers so they serve the new build.
 *
 * New processes are needed because the current one runs the application as it was built before the
 * change: only a fresh process sees the newly enabled extensions, their services and their migrations.
 */
final readonly class ConsoleAppRebuilder implements AppRebuilderInterface
{
    /** @var \Closure(list<string>, \Closure(string): void): int */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, \Closure(string): void): int)|null $run            Runs a command and returns its exit code; replaceable in tests
     * @param CompiledAssetMapperConfigReader|null                       $compiledAssets Where compiled assets are, to know whether the site serves them
     */
    public function __construct(
        private WorkerRestarterInterface $workers,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        ?\Closure $run = null,
        #[Autowire(service: 'asset_mapper.compiled_asset_mapper_config_reader')]
        private ?CompiledAssetMapperConfigReader $compiledAssets = null,
    ) {
        $this->run = $run ?? $this->runProcess(...);
    }

    public function rebuild(bool $migrate, \Closure $output): void
    {
        $this->console(['cache:clear'], $output);

        if ($migrate) {
            $this->console(['quire:migrations:migrate', '--no-interaction', '--allow-no-migration'], $output);
            $this->console(['quire:content:sync-indexes'], $output);
        }

        // Compiled assets are part of the build: the importmap includes the enabled extensions' scripts.
        if ($this->compiledAssets?->configExists('manifest.json') ?? false) {
            $this->console(['asset-map:compile'], $output);
        }

        $output($this->workers->restart() ? "Restarted the PHP workers.\n" : "No PHP workers to restart on this machine.\n");
    }

    /**
     * @param list<string>           $command
     * @param \Closure(string): void $output
     */
    private function runProcess(array $command, \Closure $output): int
    {
        return new Process($command, timeout: 600)->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }

    /**
     * @param list<string>           $arguments
     * @param \Closure(string): void $output
     */
    private function console(array $arguments, \Closure $output): void
    {
        $command = [PhpBinary::path(), $this->projectDir.'/bin/console', ...$arguments, '--env='.$this->environment];
        $exitCode = ($this->run)($command, $output);

        if (0 !== $exitCode) {
            throw new RebuildFailedException(\sprintf('"bin/console %s" failed with exit code %d. Fix the problem shown above, then run it again.', implode(' ', $arguments), $exitCode));
        }
    }
}
