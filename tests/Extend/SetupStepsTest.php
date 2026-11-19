<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\SetupSteps;
use Quire\Setup\Internal\SetupStepsPass;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupStepInterface;

final class SetupStepsTest extends ExtenderTestCase
{
    public function testExtensionsAddStepsHighestPriorityFirst(): void
    {
        $this->apply(new SetupSteps()
            ->step('acme-reviews.import', ImportReviewsStep::class)
            ->step('acme-reviews.connect', ConnectReviewsStep::class, priority: 50)
            ->step('acme-reviews.later', LaterStep::class));

        $registered = $this->parameter(SetupSteps::PARAMETER);
        self::assertIsArray($registered);
        self::assertSame(['acme-reviews.connect', 'acme-reviews.import', 'acme-reviews.later'], array_column($registered, 'id'), 'By priority, then in the order they were added.');

        // Tagged once every extender has run, whatever their order: a later Extend\Services() that
        // defines the class again, untagged, doesn't hide the step
        $this->container->register(ConnectReviewsStep::class, ConnectReviewsStep::class);
        new SetupStepsPass()->process($this->container);
        self::assertTrue($this->container->getDefinition(ConnectReviewsStep::class)->hasTag(SetupSteps::TAG));
        self::assertTrue($this->container->getDefinition(LaterStep::class)->hasTag(SetupSteps::TAG), 'A step that wasn\'t a service yet becomes one.');
        self::assertTrue($this->container->getDefinition(LaterStep::class)->isAutowired());
    }

    public function testIdsAndClasses(): void
    {
        $this->assertExtenderFails(new SetupSteps()->step('connect', ConnectReviewsStep::class), 'Step IDs start with "acme-reviews.", followed by lowercase letters');
        $this->assertExtenderFails(new SetupSteps()->step('acme-other.connect', ConnectReviewsStep::class), 'Step IDs start with "acme-reviews."');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new SetupSteps()->step('acme-reviews.connect', \stdClass::class), 'Setup steps are classes implementing Quire\Setup\SetupStepInterface.');

        $this->apply(new SetupSteps()->step('acme-reviews.connect', ConnectReviewsStep::class));
        $this->assertExtenderFails(new SetupSteps()->step('acme-reviews.connect', ConnectReviewsStep::class), 'The step is already added by "acme-reviews".');
    }
}

final class ConnectReviewsStep implements SetupStepInterface
{
    public function step(): ?SetupStep
    {
        return null;
    }
}

final class ImportReviewsStep implements SetupStepInterface
{
    public function step(): ?SetupStep
    {
        return null;
    }
}

final class LaterStep implements SetupStepInterface
{
    public function step(): ?SetupStep
    {
        return null;
    }
}
