<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Workflow\ContentWorkflow;

/**
 * "content.transition": publish, schedule, unpublish or archive an item, or take a transition that an
 * extension added to the workflow.
 *
 * @internal
 */
final readonly class TransitionContent implements ActionInterface
{
    public function __construct(
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentWorkflow $workflow,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Change an item\'s status', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('transition', ParameterType::Text, 'Transition', choices: $this->workflow->transitions()),
            new Parameter('at', ParameterType::DateTime, 'When', required: false),
        ], command: 'quire:content:transition');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->mayTransition($this->repository->get($arguments->string('content')), $arguments->string('transition'));
    }

    public function run(Arguments $arguments): Content
    {
        $item = $this->repository->get($arguments->string('content'));

        return $this->writer->transition($item->id, $item->version, $arguments->string('transition'), $arguments->dateTime('at'));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:transition', [$arguments->string('content'), $arguments->string('transition')], ['at' => $arguments->optionalString('at')]);
    }
}
