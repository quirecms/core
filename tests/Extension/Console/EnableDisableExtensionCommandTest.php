<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Console;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Console\DisableExtensionCommand;
use Quire\Extension\Console\EnableExtensionCommand;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Quire\Tests\Extension\FakeComposerInstallation;
use Quire\Tests\Extension\InMemoryExtensionStateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class EnableDisableExtensionCommandTest extends TestCase
{
    private FakeComposerInstallation $composer;

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews', overrides: ['extra' => ['quire' => ['requires' => ['quire-comments']]]])
            ->addPackage('quire/comments')
            ->addPackage('quire/seo');
    }

    public function testEnable(): void
    {
        $state = new InMemoryExtensionStateStore();
        $tester = $this->enable($state);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-seo']]));
        self::assertStringContainsString('Enabled: quire-seo.', $tester->getDisplay());
        self::assertSame(['quire-seo'], $state->enabledIds());
    }

    public function testEnableAsksBeforeEnablingRequirements(): void
    {
        $state = new InMemoryExtensionStateStore();
        $tester = $this->enable($state);
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-reviews']], ['interactive' => true]));
        self::assertStringContainsString('Required extensions will be enabled too: quire-comments.', $tester->getDisplay());
        self::assertStringContainsString('Enabled: quire-comments, quire-reviews.', $tester->getDisplay());
        self::assertSame(['quire-comments', 'quire-reviews'], $state->enabledIds());
    }

    public function testDecliningTheConfirmationChangesNothing(): void
    {
        $state = new InMemoryExtensionStateStore();
        $tester = $this->enable($state);
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-reviews']], ['interactive' => true]));
        self::assertStringContainsString('Nothing was changed.', $tester->getDisplay());
        self::assertSame([], $state->enabledIds());
    }

    public function testEnableDryRun(): void
    {
        $state = new InMemoryExtensionStateStore();
        $tester = $this->enable($state);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-reviews'], '--dry-run' => true]));
        self::assertMatchesRegularExpression('/Would enable, in this order:\s+\* quire-comments\s+\* quire-reviews/', $tester->getDisplay());
        self::assertSame([], $state->enabledIds());
    }

    public function testEnableAlreadyEnabled(): void
    {
        $tester = $this->enable(new InMemoryExtensionStateStore(['quire-seo']));

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-seo']]));
        self::assertStringContainsString('Already enabled: quire-seo.', $tester->getDisplay());
    }

    public function testEnableUnknownExtensionFailsWithSuggestion(): void
    {
        $tester = $this->enable(new InMemoryExtensionStateStore());

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['quire-sseo']]));
        self::assertStringContainsString('Did you mean "quire-seo"?', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testDisable(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-seo']);
        $tester = $this->disable($state);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-seo']]));
        self::assertStringContainsString('Disabled: quire-seo.', $tester->getDisplay());
        self::assertSame([], $state->enabledIds());
    }

    public function testDisableBlockedByDependent(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-comments', 'quire-reviews']);
        $tester = $this->disable($state);

        self::assertSame(Command::FAILURE, $tester->execute(['ids' => ['quire-comments']]));
        self::assertStringContainsString('"quire-comments" can\'t be disabled because "quire-reviews" requires it.', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(['quire-comments', 'quire-reviews'], $state->enabledIds());
    }

    public function testDisableDryRun(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-seo']);
        $tester = $this->disable($state);

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-seo'], '--dry-run' => true]));
        self::assertMatchesRegularExpression('/Would disable:\s+\* quire-seo/', $tester->getDisplay());
        self::assertSame(['quire-seo'], $state->enabledIds());
    }

    public function testDisableAlreadyDisabled(): void
    {
        $tester = $this->disable(new InMemoryExtensionStateStore());

        self::assertSame(Command::SUCCESS, $tester->execute(['ids' => ['quire-seo']]));
        self::assertStringContainsString('Already disabled: quire-seo.', $tester->getDisplay());
    }

    public function testPinnedExtensionsCannotBeChanged(): void
    {
        $pinned = new PinnedExtensionStateStore(['quire-seo']);

        $enable = $this->enable($pinned);
        self::assertSame(Command::FAILURE, $enable->execute(['ids' => ['quire-comments']]));
        self::assertStringContainsString('Extensions are pinned in configuration', $enable->getDisplay());

        $disable = $this->disable($pinned);
        self::assertSame(Command::FAILURE, $disable->execute(['ids' => ['quire-seo']]));
        self::assertStringContainsString('Extensions are pinned in configuration', $disable->getDisplay());
    }

    private function enable(ExtensionStateStoreInterface $state): CommandTester
    {
        return new CommandTester(new Command(null, new EnableExtensionCommand($this->manager($state))));
    }

    private function disable(ExtensionStateStoreInterface $state): CommandTester
    {
        return new CommandTester(new Command(null, new DisableExtensionCommand($this->manager($state))));
    }

    private function manager(ExtensionStateStoreInterface $state): ExtensionManager
    {
        return new ExtensionManager(new InstalledExtensions($this->composer->installedJsonPath()), $state, new EventDispatcher());
    }
}
