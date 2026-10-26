<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Content\ContentRepository;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Revision\Review;
use Quire\Content\Revision\Verdict;

/**
 * "content.review_changes": approve changes, ask for changes to them, or comment on them.
 *
 * @internal
 */
final readonly class ReviewChanges implements ActionInterface
{
    public function __construct(
        private PendingRevisionRepository $pending,
        private ContentRepository $repository,
        private PendingRevisionWriter $writer,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Review changes', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('pending_revision', ParameterType::PendingRevision, 'Changes'),
            new Parameter('verdict', ParameterType::Text, 'Verdict', choices: array_column(Verdict::cases(), 'value')),
            new Parameter('message', ParameterType::Text, 'Message', required: false),
        ], command: 'quire:content:pending');
    }

    public function denied(Arguments $arguments): ?string
    {
        $pending = $this->pendingRevision($arguments);
        $type = $this->repository->get($pending->contentId)->type;

        return match (true) {
            !$this->access->may($type, 'review') => 'Reviewing takes the right to review; ask someone who has it.',
            Verdict::Approve->value === $arguments->string('verdict') && $this->access->isOwn($pending) => 'Someone else approves your changes.',
            default => null,
        };
    }

    public function run(Arguments $arguments): Review
    {
        $pending = $this->pendingRevision($arguments);

        return $this->writer->review($pending->id, $pending->version, Verdict::from($arguments->string('verdict')), $this->access->actorId(), $arguments->optionalString('message'));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:pending', [$arguments->string('content')], [str_replace('_', '-', $arguments->string('verdict')) => $arguments->string('pending_revision'), 'message' => $arguments->optionalString('message')]);
    }

    private function pendingRevision(Arguments $arguments): PendingRevision
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));

        return $pending->contentId === $arguments->string('content') ? $pending : throw new \InvalidArgumentException('The changes are to another item.');
    }
}
