<?php

declare(strict_types=1);

namespace Quire\Tests\Maker;

use PHPUnit\Framework\TestCase;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Quire\Maker\Console\MakeExtensionCommand;
use Quire\Tests\Extension\FakeComposerInstallation;
use Quire\Tests\Extension\InMemoryExtensionStateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

final class MakeExtensionCommandTest extends TestCase
{
    private FakeComposerInstallation $composer;

    private string $projectDir;

    /** @var list<string> */
    private array $commands = [];

    private ?string $failing = null;

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation();
        $this->projectDir = $this->composer->projectDir();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir.'/extensions');
    }

    public function testCreatesInstallsAndEnablesTheExtension(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/reviews']));
        self::assertFileExists($this->projectDir.'/extensions/reviews/extend.php');
        self::assertFileExists($this->projectDir.'/extensions/reviews/tests/MessagesTest.php');
        self::assertSame([
            'composer config repositories.extensions {"type":"path","url":"extensions/*"}',
            'composer require acme/reviews:*@dev --no-interaction --no-scripts',
            'bin/console quire:extension:enable acme-reviews --no-interaction --env=test',
        ], $this->commands);
        self::assertStringContainsString('Reviews is ready. Open /reviews on your site', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testNoInstallOnlyCreatesTheFiles(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/reviews', '--no-install' => true, '--title' => 'Product Reviews']));
        self::assertFileExists($this->projectDir.'/extensions/reviews/composer.json');
        self::assertStringContainsString('"title": "Product Reviews"', (string) file_get_contents($this->projectDir.'/extensions/reviews/composer.json'));
        self::assertSame([], $this->commands);
    }

    public function testDirectoriesOutsideTheProjectAreNotInstalled(): void
    {
        $directory = $this->projectDir.'/../outside-'.bin2hex(random_bytes(3));
        $tester = $this->tester();

        try {
            self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/reviews', '--dir' => $directory]));
            self::assertFileExists($directory.'/extend.php');
            self::assertSame([], $this->commands);
            self::assertStringContainsString('outside the project, so it wasn\'t installed', $tester->getDisplay());
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    public function testRefusesToOverwriteAnExistingDirectory(): void
    {
        new Filesystem()->dumpFile($this->projectDir.'/extensions/reviews/keep.txt', 'mine');
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['package' => 'acme/reviews']));
        self::assertStringContainsString('"extensions/reviews" already exists and isn\'t empty.', $tester->getDisplay());
        self::assertSame('mine', file_get_contents($this->projectDir.'/extensions/reviews/keep.txt'));
    }

    public function testRejectsInvalidPackageNames(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['package' => 'Reviews']));
        self::assertStringContainsString('is not a valid package name', $tester->getDisplay());
    }

    public function testComposerFailureKeepsTheFiles(): void
    {
        $this->failing = 'require';
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['package' => 'acme/reviews']));
        self::assertFileExists($this->projectDir.'/extensions/reviews/extend.php');
        self::assertCount(2, $this->commands, 'Nothing is enabled after a failed install.');
    }

    public function testPinnedExtensionsAreInstalledButNotEnabled(): void
    {
        $tester = $this->tester(new PinnedExtensionStateStore([]));

        self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/reviews']));
        self::assertCount(2, $this->commands);
        self::assertStringContainsString('Installed, but not enabled', $tester->getDisplay());
    }

    private function tester(ExtensionStateStoreInterface $state = new InMemoryExtensionStateStore()): CommandTester
    {
        $manager = new ExtensionManager(new InstalledExtensions($this->composer->installedJsonPath()), $state, new EventDispatcher());

        return new CommandTester(new Command(null, new MakeExtensionCommand($manager, $this->projectDir, 'test', $this->recordCommand(...))));
    }

    /**
     * Stands in for running processes: records the command (without the PHP binary and project path).
     *
     * @param list<string> $command
     */
    private function recordCommand(array $command, \Closure $output): int
    {
        $words = array_map(fn (string $part): string => str_replace($this->projectDir.'/', '', $part), $command);

        if (str_ends_with($words[0], 'php') || str_contains($words[0], '/php')) {
            array_shift($words);
        }

        $this->commands[] = implode(' ', $words);

        return null !== $this->failing && \in_array($this->failing, $command, true) ? 1 : 0;
    }
}
