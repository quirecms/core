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
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionRepository;

/**
 * "content.publish_changes": merge changes into the live item now.
 *
 * @internal
 */
final readonly class PublishChanges implements ActionInterface
{
    public function __construct(
        private PendingRevisionRepository $pending,
        private ContentRepository $repository,
        private ContentWriter $writer,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Publish changes', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('pending_revision', ParameterType::PendingRevision, 'Changes'),
        ], command: 'quire:content:pending');
    }

    public function denied(Arguments $arguments): ?string
    {
        $pending = $this->pendingRevision($arguments);
        $type = $this->repository->get($pending->contentId)->type;

        return $this->access->may($type, 'publish') ? null : 'Publishing takes the right to publish; ask someone who has it.';
    }

    public function run(Arguments $arguments): Content
    {
        $pending = $this->pendingRevision($arguments);

        return $this->writer->publishPendingRevision($pending->id, $pending->version, $this->access->actorId());
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:pending', [$arguments->string('content')], ['publish' => $arguments->string('pending_revision')]);
    }

    private function pendingRevision(Arguments $arguments): PendingRevision
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));

        return $pending->contentId === $arguments->string('content') ? $pending : throw new \InvalidArgumentException('The changes are to another item.');
    }
}
