<?php

declare(strict_types=1);

namespace Quire\Content\Workflow;

use Quire\Content\Content;
use Quire\Content\Status;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The statuses content items can have and the transitions between them, as a Symfony state machine
 * named "content". Its events ("workflow.content.guard.publish", …) are dispatched as usual.
 *
 * Change statuses through {@see \Quire\Content\ContentWriter::transition()}, which also saves the item.
 *
 * @api
 */
final readonly class ContentWorkflow
{
    public const string PARAMETER = 'quire.content.workflow';

    public const string NAME = 'content';

    private StateMachine $machine;

    /** @var array<string, string> Status => ID of the extension that added it */
    private array $statuses;

    /**
     * @param array{statuses: array<string, string>, transitions: list<array{name: string, from: string, to: string, owner: string}>} $definition Compiled by {@see \Quire\Extend\ContentWorkflow}
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $definition,
        EventDispatcherInterface $dispatcher,
    ) {
        $this->statuses = $definition['statuses'];
        $transitions = array_map(static fn (array $t): Transition => new Transition($t['name'], $t['from'], $t['to']), $definition['transitions']);

        $this->machine = new StateMachine(
            new Definition(array_keys($definition['statuses']), $transitions, Status::DRAFT),
            new MethodMarkingStore(true, 'status'),
            $dispatcher,
            self::NAME,
        );
    }

    /**
     * @return array<string, string> Every status, mapped to the ID of the extension that added it
     */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /**
     * @return list<string> Names of every transition, each once, in the order they were added
     */
    public function transitions(): array
    {
        return array_values(array_unique(array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $this->machine->getDefinition()->getTransitions(),
        )));
    }

    /**
     * @return list<string> Names of the transitions the item can take now, guards included
     */
    public function enabledTransitions(Content $content): array
    {
        return array_values(array_unique(array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $this->machine->getEnabledTransitions(new ContentSubject($content)),
        )));
    }

    /**
     * A transition that leads from one status to another, e.g. "unpublish" from "published" to "draft";
     * null when none does.
     */
    public function transitionBetween(string $from, string $to): ?string
    {
        foreach ($this->machine->getDefinition()->getTransitions() as $transition) {
            if (\in_array($from, $transition->getFroms(), true) && ($transition->getTos()[0] ?? null) === $to) {
                return $transition->getName();
            }
        }

        return null;
    }

    /**
     * The status the transition leads to from the item's current status, or null when there's no such transition.
     */
    public function target(Content $content, string $transition): ?string
    {
        foreach ($this->machine->getDefinition()->getTransitions() as $candidate) {
            if ($candidate->getName() === $transition && \in_array($content->status, $candidate->getFroms(), true)) {
                $to = $candidate->getTos()[0] ?? null;

                return \is_string($to) ? $to : null;
            }
        }

        return null;
    }

    /**
     * @return list<string> Why guards block the transition; empty when it's allowed or doesn't exist
     */
    public function blockers(Content $content, string $transition): array
    {
        if (null === $this->target($content, $transition)) {
            return [];
        }

        $reasons = [];

        foreach ($this->machine->buildTransitionBlockerList(new ContentSubject($content), $transition) as $blocker) {
            $reasons[] = $blocker->getMessage();
        }

        return $reasons;
    }

    /**
     * Runs the transition through the state machine, which dispatches its events.
     *
     * @internal Use {@see \Quire\Content\ContentWriter::transition()}, which also saves the result
     *
     * @return string The new status
     */
    public function apply(Content $content, string $transition): string
    {
        $subject = new ContentSubject($content);
        $this->machine->apply($subject, $transition);

        return $subject->getStatus();
    }
}
