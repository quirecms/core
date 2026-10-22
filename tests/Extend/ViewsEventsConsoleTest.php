<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Console;
use Quire\Extend\Events;
use Quire\Extend\Views;
use Quire\Tests\Extend\Fixtures\FixtureCommand;
use Quire\Tests\Extend\Fixtures\FixtureSubscriber;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Quire\Tests\Extend\Fixtures\NotInvokable;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class ViewsEventsConsoleTest extends ExtenderTestCase
{
    public function testViewsRegisterNamespacesAndGlobals(): void
    {
        $this->apply(new Views()->namespace('reviews', __DIR__)->global('per_page', 20)->global('labels', ['a' => 'A']));

        self::assertSame([['globals' => ['per_page' => 20, 'labels' => ['a' => 'A']]]], $this->container->getExtensionConfig('twig'));
        self::assertSame([['namespace' => 'reviews', 'directory' => __DIR__, 'owner' => 'acme-reviews']], $this->container->getParameter(Views::PARAMETER), 'The template paths are set up with the theme\'s, after all extenders ran.');
    }

    public function testViewsRejectInvalidNamespaces(): void
    {
        $this->assertExtenderFails(new Views()->namespace('my-reviews', __DIR__), 'Namespaces must start with a letter');
        $this->assertExtenderFails(new Views()->namespace('Quire', __DIR__), 'reserved for core templates');
        $this->assertExtenderFails(new Views()->namespace('reviews', __DIR__.'/missing'), 'doesn\'t exist');

        $this->apply(new Views()->namespace('reviews', __DIR__));
        $this->assertExtenderFails(new Views()->namespace('reviews', __DIR__), 'The namespace is already used by "acme-reviews".');
    }

    public function testViewsRejectObjectGlobals(): void
    {
        $this->assertExtenderFails(new Views()->global('clock', new \stdClass()), 'Globals must be scalars or arrays of scalars, got stdClass');
    }

    public function testEventsRegisterTaggedListeners(): void
    {
        $this->apply(new Events()
            ->listen(RequestEvent::class, InvokableController::class, 'onRequest', priority: 10)
            ->subscribe(FixtureSubscriber::class));

        self::assertSame(
            [['event' => RequestEvent::class, 'method' => 'onRequest', 'priority' => 10]],
            $this->container->getDefinition(InvokableController::class)->getTag('kernel.event_listener'),
        );
        self::assertTrue($this->container->getDefinition(FixtureSubscriber::class)->hasTag('kernel.event_subscriber'));
    }

    public function testEventsRejectInvalidListeners(): void
    {
        $this->assertExtenderFails(
            new Events()->listen(RequestEvent::class, 'Acme\\Missing'), // @phpstan-ignore argument.type (deliberately not a class)
            'The listener class "Acme\\Missing" doesn\'t exist.',
        );
        $this->assertExtenderFails(new Events()->listen(RequestEvent::class, NotInvokable::class), 'has no method "__invoke"');
        $this->assertExtenderFails(
            new Events()->subscribe(NotInvokable::class), // @phpstan-ignore argument.type (deliberately not a subscriber)
            'must implement',
        );
    }

    public function testConsoleRegistersCommands(): void
    {
        $this->apply(new Console()->command(FixtureCommand::class));

        self::assertTrue($this->container->getDefinition(FixtureCommand::class)->isAutoconfigured());
    }

    public function testConsoleRequiresAsCommand(): void
    {
        $this->assertExtenderFails(new Console()->command(NotInvokable::class), 'needs a #[AsCommand(name: \'...\')] attribute');
    }
}
