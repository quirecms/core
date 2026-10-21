<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Console;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Console\ListExtensionsCommand;
use Quire\Extension\InstalledExtensions;
use Quire\Tests\Extension\FakeComposerInstallation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Each test keeps its FakeComposerInstallation in a local variable: the object deletes its files
 * when it's destroyed, so it must outlive the command execution.
 */
final class ListExtensionsCommandTest extends TestCase
{
    public function testShowsInstalledExtensionsAsATable(): void
    {
        $composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews', overrides: ['extra' => ['quire' => ['requires' => ['quire-comments']]]])
            ->addPackage('quire/comments');

        $tester = $this->tester($composer);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/quire-comments\s+Comments\s+quire\/comments\s+1\.2\.3\s+Extension/', $display);
        self::assertMatchesRegularExpression('/quire-reviews\s+Reviews\s+quire\/reviews\s+1\.2\.3\s+Extension\s+quire-comments/', $display);
    }

    public function testExplainsHowToInstallWhenNothingIsInstalled(): void
    {
        $composer = new FakeComposerInstallation();
        $tester = $this->tester($composer);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No Quire extensions are installed.', $tester->getDisplay());
    }

    public function testJsonFormat(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('acme/dark', 'quire-theme');
        $tester = $this->tester($composer);

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertSame([[
            'id' => 'acme-dark',
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
        ]], $data);
    }

    public function testRejectsUnknownFormats(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessageIsOrContains('Unknown format "xml". Use "table" or "json".');

        $composer = new FakeComposerInstallation();
        $this->tester($composer)->execute(['--format' => 'xml']);
    }

    private function tester(FakeComposerInstallation $composer): CommandTester
    {
        $command = new Command(null, new ListExtensionsCommand(new InstalledExtensions($composer->installedJsonPath())));

        return new CommandTester($command);
    }
}
