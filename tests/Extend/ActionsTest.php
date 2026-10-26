<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Extend\Actions;
use Quire\Extend\ExtenderContext;

final class ActionsTest extends ExtenderTestCase
{
    public function testExtensionsAddActions(): void
    {
        $this->apply(new Actions()->action('acme-reviews.approve', ApproveReview::class));

        self::assertSame([['id' => 'acme-reviews.approve', 'class' => ApproveReview::class, 'owner' => 'acme-reviews']], $this->parameter(Actions::PARAMETER));
        self::assertTrue($this->container->getDefinition(ApproveReview::class)->hasTag(Actions::TAG));
    }

    public function testIdsAndClasses(): void
    {
        $this->assertExtenderFails(new Actions()->action('approve', ApproveReview::class), 'Action IDs start with "acme-reviews.", followed by a verb');
        $this->assertExtenderFails(new Actions()->action('content.approve', ApproveReview::class), 'Action IDs start with "acme-reviews."');
        $this->assertExtenderFails(new Actions()->action('acme-reviews.Approve-It', ApproveReview::class), 'followed by a verb in lowercase letters, digits and underscores');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new Actions()->action('acme-reviews.approve', \stdClass::class), 'Actions are classes implementing Quire\Action\ActionInterface.');

        $this->apply(new Actions()->action('acme-reviews.approve', ApproveReview::class));
        $this->assertExtenderFails(new Actions()->action('acme-reviews.approve', ApproveReview::class), 'The action is already added by "acme-reviews".');
    }

    public function testCoreNamesItsActionsAfterWhatTheyActOn(): void
    {
        new Actions()->action('content.approve', ApproveReview::class)->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));

        self::assertSame([['id' => 'content.approve', 'class' => ApproveReview::class, 'owner' => 'core']], $this->parameter(Actions::PARAMETER));
    }
}

final class ApproveReview implements ActionInterface
{
    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Approve a review');
    }

    public function denied(Arguments $arguments): ?string
    {
        return null;
    }

    public function run(Arguments $arguments): null
    {
        return null;
    }

    public function commandLine(Arguments $arguments): null
    {
        return null;
    }
}
