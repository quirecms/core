<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Status;
use Quire\Content\Workflow\ContentWorkflow as Workflow;

/**
 * Adds statuses and transitions to the content workflow, e.g. an editorial review step:
 *
 *     new Extend\ContentWorkflow()
 *         ->status('in_review')
 *         ->transition('submit', from: ['draft'], to: 'in_review')
 *         ->transition('reject', from: ['in_review'], to: 'draft')
 *         ->transition('publish', from: ['in_review'], to: 'published');
 *
 * To make an existing transition conditional, listen to Symfony's guard event for it, e.g.
 * "workflow.content.guard.publish", and block it. New items always start as drafts.
 *
 * @api
 */
final class ContentWorkflow implements ExtenderInterface
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** @var list<string> */
    private array $statuses = [];

    /** @var list<array{name: string, from: list<string>, to: string}> */
    private array $transitions = [];

    public function status(string $name): self
    {
        $this->statuses[] = $name;

        return $this;
    }

    /**
     * @param list<string> $from Statuses the transition starts from
     */
    public function transition(string $name, array $from, string $to): self
    {
        $this->transitions[] = ['name' => $name, 'from' => $from, 'to' => $to];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $definition = $container->getParameterBag()->all()[Workflow::PARAMETER] ?? null;
        /** @var array{statuses: array<string, string>, transitions: list<array{name: string, from: string, to: string, owner: string}>} $definition */
        $definition = \is_array($definition) ? $definition : ['statuses' => [], 'transitions' => []];

        foreach ($this->statuses as $status) {
            $where = \sprintf('Extend\ContentWorkflow::status("%s")', $status);

            if (1 !== preg_match(self::NAME_PATTERN, $status)) {
                throw $context->error($where, 'Statuses start with a lowercase letter, followed by up to 31 lowercase letters, digits or underscores.');
            }

            if (isset($definition['statuses'][$status])) {
                throw $context->error($where, \sprintf('The status is already added by "%s".', $definition['statuses'][$status]));
            }

            $definition['statuses'][$status] = $context->id;
        }

        foreach ($this->transitions as $transition) {
            $where = \sprintf('Extend\ContentWorkflow::transition("%s")', $transition['name']);

            if (1 !== preg_match(self::NAME_PATTERN, $transition['name'])) {
                throw $context->error($where, 'Transition names start with a lowercase letter, followed by up to 31 lowercase letters, digits or underscores.');
            }

            if ([] === $transition['from']) {
                throw $context->error($where, 'The transition needs at least one status to start from.');
            }

            foreach ([...$transition['from'], $transition['to']] as $status) {
                if (!isset($definition['statuses'][$status])) {
                    throw $context->error($where, \sprintf('Unknown status "%s". Add it with status() first, or require the extension that adds it. Known: %s.', $status, implode(', ', array_keys($definition['statuses']))));
                }
            }

            foreach ($transition['from'] as $from) {
                foreach ($definition['transitions'] as $existing) {
                    if ($existing['name'] === $transition['name'] && $existing['from'] === $from) {
                        throw $context->error($where, \sprintf('A "%s" transition from "%s" already exists (added by "%s").', $transition['name'], $from, $existing['owner']));
                    }
                }

                // Symfony's state machines take one starting status per transition.
                $definition['transitions'][] = ['name' => $transition['name'], 'from' => $from, 'to' => $transition['to'], 'owner' => $context->id];
            }
        }

        if (!isset($definition['statuses'][Status::DRAFT])) {
            throw $context->error('Extend\ContentWorkflow', \sprintf('Core\'s "%s" status must be added first.', Status::DRAFT));
        }

        $container->setParameter(Workflow::PARAMETER, $definition);
    }
}
