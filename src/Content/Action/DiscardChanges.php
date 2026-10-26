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

/**
 * "content.discard_changes": delete changes without publishing them.
 *
 * @internal
 */
final readonly class DiscardChanges implements ActionInterface
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
        return new ActionDefinition('Discard changes', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('pending_revision', ParameterType::PendingRevision, 'Changes'),
        ], command: 'quire:content:pending');
    }

    public function denied(Arguments $arguments): ?string
    {
        $pending = $this->pendingRevision($arguments);
        $type = $this->repository->get($pending->contentId)->type;

        return $this->access->isOwn($pending) || $this->access->may($type, 'publish') ? null : 'Only their author, or someone who may publish, discards changes.';
    }

    public function run(Arguments $arguments): PendingRevision
    {
        $pending = $this->pendingRevision($arguments);

        return $this->writer->discard($pending->id, $this->access->actorId());
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:pending', [$arguments->string('content')], ['discard' => $arguments->string('pending_revision')]);
    }

    private function pendingRevision(Arguments $arguments): PendingRevision
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));

        return $pending->contentId === $arguments->string('content') ? $pending : throw new \InvalidArgumentException('The changes are to another item.');
    }
}
