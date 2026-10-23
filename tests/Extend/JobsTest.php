<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Job\PublishDueContent;
use Quire\Content\Job\PublishDueContentHandler;
use Quire\Extend\Jobs;
use Quire\Fixtures\Hello\Job\SendGreetingHandler;
use Quire\Jobs\Job;

final class JobsTest extends ExtenderTestCase
{
    public function testSchedulesTasks(): void
    {
        $this->apply(new Jobs()
            ->schedule('acme-reviews.digest', '0 6 * * 1', PublishDueContent::class, timezone: 'Europe/Berlin')
            ->schedule('acme-reviews.sync', 'every 15 minutes', PublishDueContent::class));

        self::assertSame([
            ['name' => 'acme-reviews.digest', 'frequency' => '0 6 * * 1', 'message' => PublishDueContent::class, 'owner' => 'acme-reviews', 'timezone' => 'Europe/Berlin'],
            ['name' => 'acme-reviews.sync', 'frequency' => 'every 15 minutes', 'message' => PublishDueContent::class, 'owner' => 'acme-reviews', 'timezone' => null],
        ], $this->container->getParameter(Jobs::PARAMETER));
    }

    public function testChecksTasks(): void
    {
        $this->assertExtenderFails(new Jobs()->schedule('digest', '@daily', PublishDueContent::class), 'Task names are lowercase words separated by dots, starting with "acme-reviews."');
        $this->assertExtenderFails(new Jobs()->schedule('acme-other.digest', '@daily', PublishDueContent::class), 'starting with "acme-reviews."');
        $this->assertExtenderFails(new Jobs()->schedule('acme-reviews.digest', 'every 30 seconds', PublishDueContent::class), '"every 30 seconds" isn\'t a frequency.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new Jobs()->schedule('acme-reviews.digest', '@daily', \stdClass::class), 'must be a class implementing '.Job::class);
        $this->assertExtenderFails(new Jobs()->schedule('acme-reviews.digest', '@daily', PublishDueContent::class, timezone: 'Berlin'), '"Berlin" isn\'t a time zone.');

        $this->apply(new Jobs()->schedule('acme-reviews.digest', '@daily', PublishDueContent::class));
        $this->assertExtenderFails(new Jobs()->schedule('acme-reviews.digest', '@hourly', PublishDueContent::class), 'already scheduled by "acme-reviews"');
    }

    public function testRegistersHandlers(): void
    {
        $this->apply(new Jobs()->handler(SendGreetingHandler::class)->handler(PublishDueContentHandler::class));

        self::assertTrue($this->container->getDefinition(SendGreetingHandler::class)->hasTag('messenger.message_handler'));
        self::assertFalse($this->container->getDefinition(PublishDueContentHandler::class)->hasTag('messenger.message_handler'), 'Its #[AsMessageHandler] tags it; twice would run it twice.');
        self::assertTrue($this->container->getDefinition(PublishDueContentHandler::class)->isAutoconfigured());

        $this->assertExtenderFails(new Jobs()->handler('Acme\Missing'), // @phpstan-ignore argument.type (deliberately not a class)
            'The class "Acme\Missing" doesn\'t exist.');
    }
}
