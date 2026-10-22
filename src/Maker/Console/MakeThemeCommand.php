<?php

declare(strict_types=1);

namespace Quire\Maker\Console;

use Quire\Maker\ThemeSkeleton;
use Quire\Runtime\PhpBinary;
use Quire\Theme\Themes;
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
    name: 'quire:make:theme',
    description: 'Create a new theme, based on the default theme or with templates of its own',
    help: <<<'HELP'
        Creates a theme in <comment>themes/<name></comment>, installs it with Composer and makes it the site's theme:

          <info>%command.full_name% acme/dark</info>                       (a child of quire-theme-default)
          <info>%command.full_name% acme/dark --parent=acme-base</info>    (a child of another theme)
          <info>%command.full_name% acme/plain --no-parent</info>          (templates of its own)

        A child theme has the parent's templates, stylesheet and settings, and a stylesheet of its own to
        change the look. A theme without a parent has a layout and content templates that render every
        standard slot. Both come with tests and pass "quire:theme:lint".

        Pass <comment>--no-install</comment> to only create the files, e.g. for a theme that will live in its own
        repository, and <comment>--dir</comment> to create them elsewhere.
        HELP,
)]
final readonly class MakeThemeCommand
{
    /** @var \Closure(list<string>, \Closure(string): void): int */
    private \Closure $run;

    /**
     * @param (\Closure(list<string>, \Closure(string): void): int)|null $run        Runs a command and returns its exit code; replaceable in tests
     * @param EventDispatcherInterface|null                              $dispatcher To load the console's listeners before the application is rebuilt
     */
    public function __construct(
        private Themes $themes,
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
        #[Argument(description: 'Composer package name, e.g. acme/dark')]
        string $package,
        #[Option(description: 'ID of the theme to inherit from')]
        string $parent = Themes::DEFAULT,
        #[Option(description: 'Create a theme with templates of its own, without a parent')]
        bool $noParent = false,
        #[Option(description: 'Human-readable name; derived from the package name by default')]
        ?string $title = null,
        #[Option(description: 'Directory to create the theme in; themes/<name> by default')]
        ?string $dir = null,
        #[Option(description: 'Only create the files; don\'t install the theme or make it the site\'s theme')]
        bool $noInstall = false,
    ): int {
        try {
            $skeleton = new ThemeSkeleton($package, $noParent ? null : $parent, $title);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null !== $skeleton->parent && !isset($this->themes->installed()[$skeleton->parent])) {
            $io->error(\sprintf('The parent theme "%s" isn\'t installed. Install it with Composer first, choose another with --parent, or pass --no-parent.', $skeleton->parent));

            return Command::FAILURE;
        }

        $directory = Path::makeAbsolute($dir ?? 'themes/'.$skeleton->name, $this->projectDir);

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
        $io->success(\sprintf('Created the theme %s (ID "%s")%s in %s/.', $skeleton->title, $skeleton->id, null === $skeleton->parent ? '' : ', a child of "'.$skeleton->parent.'",', $relativeDirectory));

        if ($noInstall) {
            $io->writeln(\sprintf('Install it with Composer, then use it with "bin/console quire:theme:use %s".', $skeleton->id));

            return Command::SUCCESS;
        }

        if (!$insideProject) {
            $io->note('The theme is outside the project, so it wasn\'t installed. Add it as a Composer path repository, require it, and use it.');

            return Command::SUCCESS;
        }

        $this->keepEndOfCommandListenersLoaded();
        $io->section('Installing the theme with Composer');
        // A path repository makes Composer install the theme from the local directory, as a symlink.
        $parentDirectory = \dirname($relativeDirectory);
        $repository = json_encode(['type' => 'path', 'url' => $parentDirectory.'/*'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        foreach ([
            ['composer', 'config', 'repositories.'.str_replace('/', '-', $parentDirectory), $repository],
            // Without the project's scripts: their cache:clear would delete the build this command runs on,
            // and the next step rebuilds the application anyway.
            ['composer', 'require', $skeleton->package.':*@dev', '--no-interaction', '--no-scripts'],
        ] as $command) {
            if (0 !== ($this->run)($command, $io->write(...))) {
                $io->error(\sprintf('"%s" failed; see the output above. The theme\'s files were kept, so you can retry.', implode(' ', $command)));

                return Command::FAILURE;
            }
        }

        $readOnlyReason = $this->themes->readOnlyReason();

        if (null !== $readOnlyReason) {
            $io->note(\sprintf('Installed, but the site still uses its theme: %s', $readOnlyReason));

            return Command::SUCCESS;
        }

        $io->section('Making it the site\'s theme');

        if (0 !== ($this->run)([PhpBinary::path(), $this->projectDir.'/bin/console', 'quire:theme:use', $skeleton->id, '--env='.$this->environment], $io->write(...))) {
            $io->error('Using the theme failed; see the output above. Its files and Composer package were kept.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('The site uses %s now. Edit the files in %s/, check it with "bin/console quire:theme:lint", and run its tests with "vendor/bin/phpunit -c %s".', $skeleton->title, $relativeDirectory, $relativeDirectory));

        return Command::SUCCESS;
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
