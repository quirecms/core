<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Assets;
use Quire\Extend\Console;
use Quire\Extend\Events;
use Quire\Extend\Views;
use Quire\Tests\Extend\Fixtures\FixtureCommand;
use Quire\Tests\Extend\Fixtures\FixtureSlot;
use Quire\Tests\Extend\Fixtures\FixtureSubscriber;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Quire\Tests\Extend\Fixtures\NotInvokable;
use Quire\Theme\Slots;
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

    public function testViewsFillSlots(): void
    {
        $this->apply(new Views()->slot('content.after_body', '@reviews/summary.html.twig')->slot('footer', FixtureSlot::class, priority: 5));

        self::assertSame([
            ['slot' => 'content.after_body', 'template' => '@reviews/summary.html.twig', 'class' => null, 'priority' => 0, 'owner' => 'acme-reviews'],
            ['slot' => 'footer', 'template' => null, 'class' => FixtureSlot::class, 'priority' => 5, 'owner' => 'acme-reviews'],
        ], $this->container->getParameterBag()->all()[Slots::PARAMETER]);
    }

    public function testViewsRejectInvalidSlots(): void
    {
        $this->assertExtenderFails(new Views()->slot('Content.AfterBody', '@reviews/x.html.twig'), 'Slot names are lowercase words separated by dots');
        $this->assertExtenderFails(new Views()->slot('footer', NotInvokable::class), 'a template name ending in ".twig", or a class implementing Quire\Theme\SlotRendererInterface');
    }

    public function testAssetsAreRegisteredInOrderWithTheirGroup(): void
    {
        $this->apply(new Assets()->stylesheet('theme/style.css')->script('acme-reviews/reviews.js', group: 'admin')->entrypoint('app'));

        self::assertSame([
            ['type' => 'stylesheet', 'path' => 'theme/style.css', 'group' => 'site', 'owner' => 'acme-reviews'],
            ['type' => 'script', 'path' => 'acme-reviews/reviews.js', 'group' => 'admin', 'owner' => 'acme-reviews'],
            ['type' => 'entrypoint', 'path' => 'app', 'group' => 'site', 'owner' => 'acme-reviews'],
        ], $this->container->getParameter(Assets::PARAMETER));
    }

    public function testAssetsRejectInvalidDeclarations(): void
    {
        $this->assertExtenderFails(new Assets()->stylesheet('/acme-reviews/style.css'), 'Use a logical path such as "acme-reviews/styles.css"');
        $this->assertExtenderFails(new Assets()->stylesheet('acme-reviews/style.scss'), 'Stylesheets must be .css files.');
        $this->assertExtenderFails(new Assets()->script('acme-reviews/app.ts'), 'Scripts must be .js or .mjs files.');
        $this->assertExtenderFails(new Assets()->stylesheet('other/style.css', group: 'backend'), 'Unknown group "backend". Use one of: site, admin.');
        $this->assertExtenderFails(new Assets()->entrypoint('app.js?x'), 'Use the name of an entrypoint in importmap.php');
        $this->assertExtenderFails(new Assets()->stylesheet('acme-reviews/missing.css'), 'The file "'.__DIR__.'/assets/missing.css" doesn\'t exist.');
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
