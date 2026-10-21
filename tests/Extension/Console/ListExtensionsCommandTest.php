<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Console;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Console\ListExtensionsCommand;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Quire\Tests\Extension\FakeComposerInstallation;
use Quire\Tests\Extension\InMemoryExtensionStateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Each test keeps its FakeComposerInstallation in a local variable: the object deletes its files
 * when it's destroyed, so it must outlive the command execution.
 */
final class ListExtensionsCommandTest extends TestCase
{
    public function testShowsStatusOfEveryInstalledExtension(): void
    {
        $composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews', overrides: ['extra' => ['quire' => ['requires' => ['quire-comments']]]])
            ->addPackage('quire/comments')
            ->addPackage('quire/seo')
            ->addPackage('quire/blog', overrides: ['extra' => ['quire' => ['requires' => ['quire-seo']]]]);

        // quire-blog requires quire-seo, which isn't enabled; "gone" was uninstalled.
        $tester = $this->tester($composer, new InMemoryExtensionStateStore(['quire-blog', 'quire-comments', 'quire-reviews', 'gone']));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/quire-blog\s+broken\s+Blog/', $display);
        self::assertMatchesRegularExpression('/quire-comments\s+enabled \(1\)\s+Comments\s+quire\/comments\s+1\.2\.3\s+Extension/', $display);
        self::assertMatchesRegularExpression('/quire-reviews\s+enabled \(2\)\s+Reviews\s+quire\/reviews\s+1\.2\.3\s+Extension\s+quire-comments/', $display);
        self::assertMatchesRegularExpression('/quire-seo\s+disabled\s+Seo/', $display);
        self::assertMatchesRegularExpression('/gone\s+missing/', $display);
        // Messages wrap at the terminal width; compare them with whitespace collapsed.
        $text = preg_replace('/\s+/', ' ', $display) ?? '';
        self::assertStringContainsString('"quire-blog" is enabled but can\'t run: it requires "quire-seo", which isn\'t enabled.', $text);
        self::assertStringContainsString('"gone" is enabled but no longer installed.', $text);
    }

    public function testExplainsHowToInstallWhenNothingIsInstalled(): void
    {
        $composer = new FakeComposerInstallation();
        $tester = $this->tester($composer);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No Quire extensions are installed.', $tester->getDisplay());
    }

    public function testMentionsPinnedConfiguration(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('quire/seo');
        $tester = $this->tester($composer, new PinnedExtensionStateStore(['quire-seo']));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Extensions are pinned in configuration', $tester->getDisplay());
    }

    public function testJsonFormat(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('acme/dark', 'quire-theme');
        $tester = $this->tester($composer, new InMemoryExtensionStateStore(['acme-dark', 'gone']));

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'id' => 'acme-dark',
                'status' => 'enabled',
                'position' => 1,
                'problem' => null,
                'package' => 'acme/dark',
                'type' => 'quire-theme',
                'version' => '1.2.3',
                'title' => 'Dark',
                'description' => null,
                'category' => null,
                'icon' => null,
                'requires' => [],
                'optional' => [],
                'path' => $composer->packagePath('acme/dark'),
            ],
            ['id' => 'gone', 'status' => 'missing', 'position' => null, 'problem' => 'enabled, but no longer installed'],
        ], $data);
    }

    public function testRejectsUnknownFormats(): void
    {
        $composer = new FakeComposerInstallation();

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessageIsOrContains('Unknown format "xml". Use "table" or "json".');

        $this->tester($composer)->execute(['--format' => 'xml']);
    }

    private function tester(FakeComposerInstallation $composer, ExtensionStateStoreInterface $state = new InMemoryExtensionStateStore()): CommandTester
    {
        $installed = new InstalledExtensions($composer->installedJsonPath());
        $manager = new ExtensionManager($installed, $state, new EventDispatcher());

        return new CommandTester(new Command(null, new ListExtensionsCommand($installed, $manager)));
    }
}
