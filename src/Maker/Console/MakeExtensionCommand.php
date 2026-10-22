<?php

declare(strict_types=1);

namespace Quire\Maker\Console;

use Quire\Extension\ExtensionManager;
use Quire\Maker\ExtensionSkeleton;
use Quire\Runtime\PhpBinary;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'quire:make:extension',
    description: 'Create a new extension with a working page, setting, migration and test',
    help: <<<'HELP'
        Creates a ready-to-use extension in <comment>extensions/<name></comment>, installs it with Composer and
        enables it:

          <info>%command.full_name% acme/reviews</info>

        The extension has a page (a route, controller and template), a setting, a database migration
        and passing tests, as a starting point to change.

        Pass <comment>--no-install</comment> to only create the files, e.g. for an extension that will live in its own
        repository, and <comment>--dir</comment> to create them elsewhere.
        HELP,
)]
final readonly class MakeExtensionCommand
{
    /** @var \Closure(list<string>, \Closure(string): void): int */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, \Closure(string): void): int)|null $run        Runs a command and returns its exit code; replaceable in tests
     * @param EventDispatcherInterface|null                              $dispatcher To load the console's listeners before the application is rebuilt
     */
    public function __construct(
        private ExtensionManager $extensions,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
        ?\Closure $run = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
        $this->run = $run ?? $this->runProcess(...);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Composer package name, e.g. acme/reviews')]
        string $package,
        #[Option(description: 'Human-readable name; derived from the package name by default')]
        ?string $title = null,
        #[Option(description: 'Directory to create the extension in; extensions/<name> by default')]
        ?string $dir = null,
        #[Option(description: 'Only create the files; don\'t install or enable the extension')]
        bool $noInstall = false,
    ): int {
        try {
            $skeleton = new ExtensionSkeleton($package, $title);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $directory = Path::makeAbsolute($dir ?? 'extensions/'.$skeleton->name, $this->projectDir);

        if (is_dir($directory) && [] !== array_diff((array) scandir($directory), ['.', '..'])) {
            $io->error(\sprintf('"%s" already exists and isn\'t empty. Choose another name, or pass --dir.', Path::makeRelative($directory, $this->projectDir)));

            return Command::FAILURE;
        }

        $filesystem = new Filesystem();

        foreach ($skeleton->files() as $relativePath => $contents) {
            $filesystem->dumpFile($directory.'/'.$relativePath, $contents);
        }

        $insideProject = Path::isBasePath($this->projectDir, $directory);
        $relativeDirectory = $insideProject ? Path::makeRelative($directory, $this->projectDir) : $directory;
        $io->success(\sprintf('Created %s (ID "%s") in %s/.', $skeleton->title, $skeleton->id, $relativeDirectory));

        if ($noInstall) {
            $io->writeln('Install it with Composer, then enable it with "bin/console quire:extension:enable '.$skeleton->id.'".');

            return Command::SUCCESS;
        }

        if (!$insideProject) {
            $io->note('The extension is outside the project, so it wasn\'t installed. Add it as a Composer path repository, require it, and enable it.');

            return Command::SUCCESS;
        }

        $installed = $this->install($io, $skeleton, \dirname($relativeDirectory));

        if (!$installed) {
            return Command::FAILURE;
        }

        $readOnlyReason = $this->extensions->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->note(\sprintf('Installed, but not enabled: %s Add "%s" there.', $readOnlyReason, $skeleton->id));

            return Command::SUCCESS;
        }

        $io->section('Enabling the extension');

        if (0 !== $this->console(['quire:extension:enable', $skeleton->id, '--no-interaction'], $io)) {
            $io->error('Enabling failed; see the output above. The extension\'s files and Composer package were kept.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('%s is ready. Open %s on your site, edit the files in %s/, and run its tests with "vendor/bin/phpunit -c %s".', $skeleton->title, $skeleton->path, $relativeDirectory, $relativeDirectory));

        return Command::SUCCESS;
    }

    private function install(SymfonyStyle $io, ExtensionSkeleton $skeleton, string $parentDirectory): bool
    {
        $this->keepEndOfCommandListenersLoaded();
        $io->section('Installing the extension with Composer');

        // A path repository makes Composer install the extension from the local directory, as a symlink.
        $repository = json_encode(['type' => 'path', 'url' => $parentDirectory.'/*'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        foreach ([
            ['composer', 'config', 'repositories.'.str_replace('/', '-', $parentDirectory), $repository],
            // Without the project's scripts: their cache:clear would delete the build this command runs on,
            // and the next step rebuilds the application anyway.
            ['composer', 'require', $skeleton->package.':*@dev', '--no-interaction', '--no-scripts'],
        ] as $command) {
            if (0 !== ($this->run)($command, $io->write(...))) {
                $io->error(\sprintf('"%s" failed; see the output above. The extension\'s files were kept, so you can retry.', implode(' ', $command)));

                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $arguments
     */
    private function console(array $arguments, SymfonyStyle $io): int
    {
        return ($this->run)([PhpBinary::path(), $this->projectDir.'/bin/console', ...$arguments, '--env='.$this->environment], $io->write(...));
    }

    /**
     * @param list<string>           $command
     * @param \Closure(string): void $output
     */
    private function runProcess(array $command, \Closure $output): int
    {
        return new Process($command, $this->projectDir, timeout: 900)->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }

    /**
     * Rebuilding the application in other processes, as installing does more than once, deletes the
     * build this command runs on, while it still has to run the console's listeners when it ends.
     * Loading them now keeps them working.
     */
    private function keepEndOfCommandListenersLoaded(): void
    {
        foreach ([ConsoleEvents::TERMINATE, ConsoleEvents::ERROR] as $event) {
            $this->dispatcher?->getListeners($event);
        }
    }
}
