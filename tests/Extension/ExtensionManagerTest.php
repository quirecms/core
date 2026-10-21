<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Event\ExtensionDisabled;
use Quire\Extension\Event\ExtensionEnabled;
use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Extension\State\PinnedExtensionStateStore;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ExtensionManagerTest extends TestCase
{
    private FakeComposerInstallation $composer;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews', overrides: ['extra' => ['quire' => ['requires' => ['quire-comments']]]])
            ->addPackage('quire/comments')
            ->addPackage('quire/seo');
    }

    public function testEnableAddsRequirementsAndDispatchesEventsInBootOrder(): void
    {
        $state = new InMemoryExtensionStateStore();
        $manager = $this->manager($state);

        $enabled = $manager->enable(['quire-reviews']);

        self::assertSame(['quire-comments', 'quire-reviews'], array_map(static fn (\Quire\Extension\ExtensionManifest $m): string => $m->id, $enabled));
        self::assertSame(['quire-comments', 'quire-reviews'], $state->enabledIds());
        self::assertSame(['enabled:quire-comments', 'enabled:quire-reviews'], $this->events);
        self::assertSame(['quire-comments', 'quire-reviews'], $manager->resolve()->bootOrderIds());
        self::assertTrue($manager->isEnabled('quire-reviews'));
        self::assertFalse($manager->isEnabled('quire-seo'));
    }

    public function testEnablingAnEnabledExtensionChangesNothing(): void
    {
        $manager = $this->manager(new InMemoryExtensionStateStore(['quire-seo']));

        self::assertSame([], $manager->enable(['quire-seo']));
        self::assertSame([], $this->events);
    }

    public function testDisableDispatchesDependentsFirst(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-comments', 'quire-reviews', 'quire-seo']);
        $manager = $this->manager($state);

        self::assertSame(['quire-comments', 'quire-reviews'], $manager->disable(['quire-comments', 'quire-reviews']));
        self::assertSame(['quire-seo'], $state->enabledIds());
        self::assertSame(['disabled:quire-reviews', 'disabled:quire-comments'], $this->events);
    }

    public function testDisableIsBlockedByEnabledDependents(): void
    {
        $state = new InMemoryExtensionStateStore(['quire-comments', 'quire-reviews']);

        try {
            $this->manager($state)->disable(['quire-comments']);
            self::fail('Disabling a required extension was allowed.');
        } catch (DependencyException) {
            self::assertSame(['quire-comments', 'quire-reviews'], $state->enabledIds(), 'Nothing changed.');
            self::assertSame([], $this->events);
        }
    }

    public function testDisablingAnUninstalledExtensionCleansUpItsState(): void
    {
        $state = new InMemoryExtensionStateStore(['gone']);

        self::assertSame(['gone'], $this->manager($state)->disable(['gone']));
        self::assertSame([], $state->enabledIds());
        self::assertSame(['disabled:gone'], $this->events);
    }

    public function testPinnedStateCannotBeChanged(): void
    {
        $manager = $this->manager(new PinnedExtensionStateStore(['quire-seo']));

        self::assertSame(['quire-seo'], $manager->resolve()->bootOrderIds());
        self::assertNotNull($manager->readOnlyReason());

        $this->expectException(ExtensionStateException::class);
        $this->expectExceptionMessageIsOrContains('Extensions are pinned in configuration');

        $manager->enable(['quire-comments']);
    }

    private function manager(ExtensionStateStoreInterface $state): ExtensionManager
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ExtensionEnabled::class, function (ExtensionEnabled $event): void {
            $this->events[] = 'enabled:'.$event->extension->id;
        });
        $dispatcher->addListener(ExtensionDisabled::class, function (ExtensionDisabled $event): void {
            $this->events[] = 'disabled:'.$event->id;
        });

        return new ExtensionManager(new InstalledExtensions($this->composer->installedJsonPath()), $state, $dispatcher);
    }
}
