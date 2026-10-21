<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Workflow\ContentWorkflow as Workflow;
use Quire\Extend\ContentWorkflow;
use Quire\Extend\ExtenderContext;

final class ContentWorkflowTest extends ExtenderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        new ContentWorkflow()
            ->status('draft')
            ->status('published')
            ->transition('publish', from: ['draft'], to: 'published')
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));
    }

    public function testAddsStatusesAndTransitions(): void
    {
        $this->apply(new ContentWorkflow()
            ->status('in_review')
            ->transition('submit', from: ['draft'], to: 'in_review')
            ->transition('publish', from: ['in_review'], to: 'published'));

        $definition = $this->definition();

        self::assertSame(['draft' => 'core', 'published' => 'core', 'in_review' => 'acme-reviews'], $definition['statuses']);
        self::assertSame([
            ['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'owner' => 'core'],
            ['name' => 'submit', 'from' => 'draft', 'to' => 'in_review', 'owner' => 'acme-reviews'],
            ['name' => 'publish', 'from' => 'in_review', 'to' => 'published', 'owner' => 'acme-reviews'],
        ], $definition['transitions']);
    }

    public function testInvalidDefinitions(): void
    {
        $this->assertExtenderFails(new ContentWorkflow()->status('In Review'), 'Statuses start with a lowercase letter');
        $this->assertExtenderFails(new ContentWorkflow()->status('draft'), 'The status is already added by "core".');
        $this->assertExtenderFails(new ContentWorkflow()->transition('Submit', from: ['draft'], to: 'published'), 'Transition names start with a lowercase letter');
        $this->assertExtenderFails(new ContentWorkflow()->transition('submit', from: [], to: 'published'), 'The transition needs at least one status to start from.');
        $this->assertExtenderFails(new ContentWorkflow()->transition('submit', from: ['draft'], to: 'in_review'), 'Unknown status "in_review". Add it with status() first, or require the extension that adds it. Known: draft, published.');
        $this->assertExtenderFails(new ContentWorkflow()->transition('publish', from: ['draft'], to: 'published'), 'A "publish" transition from "draft" already exists (added by "core").');
    }

    public function testCoreMustDefineTheDraftStatusFirst(): void
    {
        $this->container->getParameterBag()->remove(Workflow::PARAMETER);

        $this->assertExtenderFails(new ContentWorkflow()->status('in_review'), 'Core\'s "draft" status must be added first.');
    }

    /**
     * @return array{statuses: array<string, string>, transitions: list<array{name: string, from: string, to: string, owner: string}>}
     */
    private function definition(): array
    {
        /** @var array{statuses: array<string, string>, transitions: list<array{name: string, from: string, to: string, owner: string}>} $definition */
        $definition = $this->container->getParameterBag()->all()[Workflow::PARAMETER];

        return $definition;
    }
}
