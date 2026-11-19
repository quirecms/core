<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use PHPUnit\Framework\Attributes\DataProvider;
use Quire\Extend\Actions;
use Quire\Extend\ContentChecks;
use Quire\Extend\Events;
use Quire\Extend\ExtenderInterface;
use Quire\Extend\Formatter;
use Quire\Extend\Head;
use Quire\Extend\Jobs;
use Quire\Extend\Routes;
use Quire\Extend\Services;
use Quire\Fixtures\Hello;
use Quire\Formatter\Formatter as FormatterService;
use Quire\Tests\Extend\Fixtures\FixtureAction;
use Quire\Tests\Extend\Fixtures\FixtureSubscriber;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Every extender that tags a class keeps its tag whatever the order of an extension's extenders: an
 * Extend\Services()->autoload() of the same classes, before or after it, never drops it.
 */
final class ServicesAutoloadOrderTest extends ExtenderTestCase
{
    private const string HELLO = __DIR__.'/../../../../tests/Fixtures/extensions/hello/src';

    /**
     * @return iterable<string, array{ExtenderInterface, class-string, string, Services}>
     */
    public static function taggingExtenders(): iterable
    {
        $hello = new Services()->autoload('Quire\\Fixtures\\Hello\\', self::HELLO, exclude: [self::HELLO.'/Controller']);
        $fixtures = new Services()->autoload('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures');

        yield 'ContentChecks' => [new ContentChecks()->check('acme-reviews.shouting', Hello\Check\ShoutingCheck::class, 'Titles aren\'t shouted'), Hello\Check\ShoutingCheck::class, ContentChecks::TAG, $hello];
        yield 'Actions' => [new Actions()->action('acme-reviews.approve', FixtureAction::class), FixtureAction::class, Actions::TAG, $fixtures];
        yield 'Head' => [new Head()->contributor(Hello\Theme\VerificationTag::class), Hello\Theme\VerificationTag::class, Head::TAG, $hello];
        yield 'Formatter' => [new Formatter()->shortcode('greeting', Hello\Formatter\GreetingShortcode::class), Hello\Formatter\GreetingShortcode::class, FormatterService::SERVICE_TAG, $hello];
        yield 'Events, a listener' => [new Events()->listen(ResponseEvent::class, Hello\FixtureHeaderListener::class), Hello\FixtureHeaderListener::class, 'kernel.event_listener', $hello];
        yield 'Events, a subscriber' => [new Events()->subscribe(FixtureSubscriber::class), FixtureSubscriber::class, 'kernel.event_subscriber', $fixtures];
        yield 'Jobs' => [new Jobs()->handler(Hello\Job\SendGreetingHandler::class), Hello\Job\SendGreetingHandler::class, 'messenger.message_handler', $hello];
        yield 'Routes' => [new Routes()->get('/hello', 'acme_reviews_hello', InvokableController::class), InvokableController::class, 'controller.service_arguments', $fixtures];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('taggingExtenders')]
    public function testAnAutoloadAfterTheExtenderKeepsItsTag(ExtenderInterface $extender, string $class, string $tag, Services $autoload): void
    {
        $this->apply($extender);
        $before = $this->container->getDefinition($class);
        $this->apply($autoload);

        $definition = $this->container->getDefinition($class);
        self::assertTrue($definition->hasTag($tag), 'The tag stays.');
        self::assertSame($before->getTags(), $definition->getTags(), 'The definition is the one the extender made.');
        self::assertSame($before->isPublic(), $definition->isPublic());
        self::assertTrue($definition->isAutowired() && $definition->isAutoconfigured());
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('taggingExtenders')]
    public function testAnAutoloadBeforeTheExtenderKeepsItsTag(ExtenderInterface $extender, string $class, string $tag, Services $autoload): void
    {
        $this->apply($autoload);
        $this->apply($extender);

        self::assertTrue($this->container->getDefinition($class)->hasTag($tag));
    }

    public function testControllersLoadedAfterAnExtenderKeepItsTagsAndGetTheirOwn(): void
    {
        $this->apply(new Events()->subscribe(FixtureSubscriber::class));
        $this->apply(new Routes()->controllers('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures'));

        $definition = $this->container->getDefinition(FixtureSubscriber::class);
        self::assertTrue($definition->hasTag('kernel.event_subscriber'), 'The extender\'s tag stays.');
        self::assertTrue($definition->hasTag('controller.service_arguments'), 'And it\'s a controller too.');
        self::assertTrue($definition->isPublic());
        self::assertTrue($this->container->getDefinition(InvokableController::class)->hasTag('controller.service_arguments'), 'Classes it didn\'t have are registered as before.');
    }

    public function testAClassAnAutoloadHasNoDefinitionForIsRegisteredAsBefore(): void
    {
        $this->apply(new Services()->autoload('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures'));

        $definition = $this->container->getDefinition(FixtureAction::class);
        self::assertTrue($definition->isAutowired() && $definition->isAutoconfigured());
        self::assertSame([], $definition->getTags());
    }
}
