<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionSwitcher;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\RebuildFailedException;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ExtensionSwitcherTest extends TestCase
{
    private FakeComposerInstallation $composer;

    private FakeAppRebuilder $rebuilder;

    private FakeExtensionPurger $purger;

    protected function setUp(): void
    {
        $this->rebuilder = new FakeAppRebuilder();
        $this->purger = new FakeExtensionPurger();
        $this->composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews', overrides: ['extra' => ['quire' => ['requires' => ['quire-comments']]]])
            ->addPackage('quire/comments')
            ->addPackage('quire/seo');
    }

    public function testEnablingEnablesWhatIsRequiredAndRebuildsWithMigrations(): void
    {
        $state = new InMemoryExtensionStateStore();
        $output = '';

        $enabled = $this->switcher($state)->enable(['quire-reviews'], static function (string $text) use (&$output): void {
            $output .= $text;
        });

        self::assertSame(['quire-comments', 'quire-reviews'], $enabled);
        self::assertSame(['quire-comments', 'quire-reviews'], $state->enabledIds());
        self::assertSame(['migrate'], $this->rebuilder->calls);
        self::assertStringContainsString('Rebuilding the application and running migrations', $output);
        self::assertSame([], $this->switcher($state)->enable(['quire-reviews'], static function (): void {}), 'Nothing to do the second time.');
    }

    public function testAFailedRebuildDisablesThemAgain(): void
    {
        $state = new InMemoryExtensionStateStore();
        $this->rebuilder->failNext = true;

        try {
            $this->switcher($state)->enable(['quire-reviews'], static function (): void {});
            self::fail('The failure was ignored.');
        } catch (RebuildFailedException $e) {
            self::assertStringEndsWith('The extensions were disabled again: quire-comments, quire-reviews.', $e->getMessage());
        }

        self::assertSame([], $state->enabledIds());
        self::assertSame(['migrate', 'no-migrate'], $this->rebuilder->calls, 'Rebuilt again without them.');
    }

    public function testDisablingWithItsDataDeletesDependentsFirst(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-comments', 'quire-reviews']);

        self::assertNull($this->switcher($state)->purgeProblem(['quire-comments', 'quire-reviews']));
        $disabled = $this->switcher($state)->disable(['quire-comments', 'quire-reviews'], true, static function (): void {});

        self::assertEqualsCanonicalizing(['quire-comments', 'quire-reviews'], $disabled);
        self::assertSame(['quire-reviews', 'quire-comments'], $this->purger->purged);
        self::assertSame([], $state->enabledIds());
    }

    public function testOnlyRunningExtensionsArePurged(): void
    {
        // Enabled but broken: quire-comments, which it requires, isn't enabled.
        $state = new InMemoryExtensionStateStore(['quire-reviews']);

        self::assertStringStartsWith('Only running extensions can be purged', (string) $this->switcher($state)->purgeProblem(['quire-reviews']));
        $this->expectException(ExtensionStateException::class);
        $this->switcher($state)->disable(['quire-reviews'], true, static function (): void {});
    }

    private function switcher(InMemoryExtensionStateStore $state): ExtensionSwitcher
    {
        return new ExtensionSwitcher(new ExtensionManager(new InstalledExtensions($this->composer->installedJsonPath()), $state, new EventDispatcher()), $this->purger, $this->rebuilder);
    }
}
