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
use Quire\Content\Revision\CommentAnchor;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Revision\PendingRevisionRepository;

/**
 * "content.comment_changes": comment on changes, optionally on text of them, or reply to a comment.
 * Their author, reviewers and those who may edit the item take part.
 *
 * @internal
 */
final readonly class CommentOnChanges implements ActionInterface
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
        return new ActionDefinition('Comment on changes', [
            new Parameter('content', ParameterType::Content, 'Item'),
            new Parameter('pending_revision', ParameterType::PendingRevision, 'Changes'),
            new Parameter('message', ParameterType::Text, 'Comment'),
            new Parameter('property', ParameterType::Text, 'On', required: false),
            new Parameter('quote', ParameterType::Text, 'About the text', required: false),
            new Parameter('line', ParameterType::Integer, 'At the line', required: false),
            new Parameter('reply_to', ParameterType::Text, 'In reply to', required: false),
        ], command: 'quire:content:comments');
    }

    public function denied(Arguments $arguments): ?string
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));
        $item = $this->repository->get($pending->contentId);

        return $this->access->isOwn($pending) || $this->access->may($item->type, 'review') || $this->access->mayEdit($item)
            ? null
            : 'Comments on changes are for their author, reviewers and those who may edit the item.';
    }

    public function run(Arguments $arguments): Comment
    {
        $pending = $this->pending->get($arguments->string('pending_revision'));

        if ($pending->contentId !== $arguments->string('content')) {
            throw new \InvalidArgumentException('The changes are to another item.');
        }

        $quote = $arguments->optionalString('quote');
        $anchor = null === $quote ? null : new CommentAnchor($arguments->optionalString('property') ?? 'body', $quote, $arguments->integer('line') ?? 1);

        return $this->comments->add($pending->id, $arguments->string('message'), $this->access->actorId(), $anchor, $arguments->optionalString('reply_to'));
    }

    public function commandLine(Arguments $arguments): string
    {
        $line = $arguments->integer('line');

        return CommandLine::of('quire:content:comments', [$arguments->string('content'), $arguments->string('pending_revision')], [
            'add' => $arguments->string('message'),
            'property' => $arguments->has('quote') ? $arguments->optionalString('property') : null,
            'quote' => $arguments->optionalString('quote'),
            'line' => null === $line ? null : (string) $line,
            'reply-to' => $arguments->optionalString('reply_to'),
        ]);
    }
}
