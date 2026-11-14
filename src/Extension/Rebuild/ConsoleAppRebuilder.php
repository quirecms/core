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
        #[Autowire(param: 'kernel.debug')]
        private bool $debug = false,
    ) {
        $this->run = $run ?? $this->runProcess(...);
    }

    public function rebuild(bool $migrate, \Closure $output): void
    {
        // In debug mode, booting rebuilds a stale build next to the current one, which processes still
        // running (like the command that asked for this rebuild) keep using. cache:clear would delete it
        // under them.
        $this->console([$this->debug ? 'cache:warmup' : 'cache:clear'], $output);

        if ($migrate) {
            $this->console(['quire:migrations:migrate', '--no-interaction', '--allow-no-migration'], $output);
            $this->console(['quire:content:sync-indexes'], $output);
        }

        // The npm packages of extensions, e.g. of one installed without Composer's scripts, which download them.
        $this->console(['importmap:install'], $output);

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
        // Wide enough that an error's box isn't wrapped mid-word, so its message can be quoted.
        return new Process($command, env: ['COLUMNS' => '1000'], timeout: 600)->run(static function (string $type, string $buffer) use ($output): void {
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
        $printed = '';
        $exitCode = ($this->run)($command, static function (string $text) use ($output, &$printed): void {
            $printed .= $text;
            $output($text);
        });

        if (0 !== $exitCode) {
            $reason = $this->error($printed);

            throw new RebuildFailedException(null === $reason ? \sprintf('"bin/console %s" failed with exit code %d. Fix the problem shown above, then run it again.', implode(' ', $arguments), $exitCode) : \sprintf('"bin/console %s" failed: %s', implode(' ', $arguments), $reason));
        }
    }

    /**
     * The message of the error a console command printed, without its box: so whoever asked for the
     * rebuild (e.g. the admin, which doesn't show the output) can tell why it failed.
     */
    private function error(string $output): ?string
    {
        // Symfony prints an error as "In File.php line 51:", then its message's lines, padded, then an
        // empty line; the error first, then the ones that caused it.
        if (1 !== preg_match('/^In [^\n]+ line \d+:\n((?:[ \t]*\S[^\n]*\n|[ \t]+\n)+?)\n/m', str_replace("\r\n", "\n", $output), $match)) {
            return null;
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $match[1])), static fn (string $line): bool => '' !== $line
            // Verbose output names the error's class first.
            && 1 !== preg_match('/^\[[\w\\\\]+\]( \(\d+\))?$/', $line)));

        return [] === $lines ? null : mb_strimwidth(implode(' ', $lines), 0, 2000, '…');
    }
}
