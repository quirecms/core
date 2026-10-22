<?php

declare(strict_types=1);

namespace Quire\Tests\Maker;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Maker\Console\MakeThemeCommand;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Tests\Extension\FakeComposerInstallation;
use Quire\Tests\Extension\InMemoryExtensionStateStore;
use Quire\Theme\Themes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

final class MakeThemeCommandTest extends TestCase
{
    private FakeComposerInstallation $composer;

    private string $projectDir;

    /** @var list<string> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation()->addPackage('quire/theme-default', 'quire-theme');
        $this->projectDir = $this->composer->projectDir();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir.'/themes');
    }

    public function testCreatesAChildOfTheDefaultThemeInstallsAndUsesIt(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/dark']));
        self::assertFileExists($this->projectDir.'/themes/dark/assets/styles.css');
        self::assertFileExists($this->projectDir.'/themes/dark/tests/ThemeTest.php');
        self::assertDirectoryDoesNotExist($this->projectDir.'/themes/dark/templates', 'A child theme starts with its parent\'s templates.');
        self::assertSame([
            'composer config repositories.themes {"type":"path","url":"themes/*"}',
            'composer require acme/dark:*@dev --no-interaction --no-scripts',
            'bin/console quire:theme:use acme-dark --env=test',
        ], $this->commands);
        self::assertStringContainsString('The site uses Dark now.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testAThemeWithoutParentHasItsOwnTemplates(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['package' => 'acme/plain', '--no-parent' => true, '--no-install' => true]));

        self::assertFileExists($this->projectDir.'/themes/plain/templates/layout.html.twig');
        self::assertFileExists($this->projectDir.'/themes/plain/templates/content/single.html.twig');
        self::assertStringNotContainsString('"parent"', (string) file_get_contents($this->projectDir.'/themes/plain/composer.json'));
        self::assertSame([], $this->commands);
    }

    public function testTheParentMustBeInstalled(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['package' => 'acme/dark', '--parent' => 'acme-base']));
        self::assertStringContainsString('The parent theme "acme-base" isn\'t installed.', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->projectDir.'/themes/dark');
    }

    public function testAPinnedThemeStays(): void
    {
        $tester = $this->tester(pinned: true);

        self::assertSame(Command::SUCCESS, $tester->execute(['package' => 'acme/dark']));
        self::assertCount(2, $this->commands, 'Installed, but not used.');
        self::assertStringContainsString('Installed, but the site still uses its theme', $tester->getDisplay());
    }

    public function testRefusesInvalidNamesAndExistingDirectories(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['package' => 'Dark']));

        new Filesystem()->dumpFile($this->projectDir.'/themes/dark/keep.txt', '');
        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['package' => 'acme/dark']));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    private function tester(bool $pinned = false): CommandTester
    {
        $installed = new InstalledExtensions($this->composer->installedJsonPath());
        $extensions = new ExtensionManager($installed, new InMemoryExtensionStateStore(), new EventDispatcher());
        // Only used to read the selected theme, which these tests don't.
        $settings = new Settings(new SettingsSchema([]), DriverManager::getConnection(['driver' => 'pdo_pgsql']), new EventDispatcher());
        $themes = new Themes($installed, $extensions, $settings, configured: null, chain: [], problem: null, pinned: $pinned);

        return new CommandTester(new Command(null, new MakeThemeCommand($themes, $this->projectDir, 'test', $this->recordCommand(...))));
    }

    /**
     * @param list<string> $command
     */
    private function recordCommand(array $command): int
    {
        // Console commands start with the PHP binary and the full path of bin/console.
        if (str_ends_with($command[1] ?? '', '/bin/console')) {
            $command = ['bin/console', ...\array_slice($command, 2)];
        }

        $this->commands[] = implode(' ', $command);

        return 0;
    }
}
