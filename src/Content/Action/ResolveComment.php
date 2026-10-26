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
use Quire\Content\Revision\Comment;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Revision\PendingRevisionRepository;

/**
 * "content.resolve_comment": mark a thread of comments on changes resolved, or open again. Its writer,
 * the changes' author and reviewers do.
 *
 * @internal
 */
final readonly class ResolveComment implements ActionInterface
{
    public function __construct(
        private PendingRevisionRepository $pending,
        private ContentRepository $repository,
        private PendingRevisionComments $comments,
        private ContentAccess $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Resolve a comment', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('pending_revision', ParameterType::PendingRevision, 'Changes'),
            new Parameter('comment', ParameterType::Text, 'Comment'),
            new Parameter('reopen', ParameterType::Bool, 'Open it again', required: false),
        ], command: 'quire:content:comments');
    }

    public function denied(Arguments $arguments): ?string
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));
        $comment = $this->comments->get($arguments->string('comment'));
        $actor = $this->access->actorId();

        return $this->access->isOwn($pending) || (null !== $actor && $comment->authorId === $actor) || $this->access->may($this->repository->get($pending->contentId)->type, 'review')
            ? null
            : 'Threads are resolved by who started them, the changes\' author or a reviewer.';
    }

    public function run(Arguments $arguments): Comment
    {
        $comment = $this->comments->get($arguments->string('comment'));

        if ($comment->pendingRevisionId !== $arguments->string('pending_revision')) {
            throw new \InvalidArgumentException('The comment is on other changes.');
        }

        return $this->comments->resolve($comment->id, $this->access->actorId(), !$arguments->bool('reopen'));
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:content:comments', [$arguments->string('content'), $arguments->string('pending_revision')], [
            ($arguments->bool('reopen') ? 'reopen' : 'resolve') => $arguments->string('comment'),
        ]);
    }
}
