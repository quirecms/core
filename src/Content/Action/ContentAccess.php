<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Status;
use Quire\User\CurrentUser;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may do what with content, for content's actions: the abilities "content.<type>.<verb>".
 *
 * @internal
 */
final readonly class ContentAccess
{
    /** Transitions that change what visitors see, so they take the right to publish */
    private const array PUBLISHING = [Status::PUBLISH, Status::SCHEDULE, Status::UNPUBLISH, Status::ARCHIVE];

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private CurrentUser $users,
    ) {
    }

    public function may(string $type, string $verb, ?Content $item = null): bool
    {
        return $this->authorization->isGranted(\sprintf('content.%s.%s', $type, $verb), $item);
    }

    public function mayEdit(Content $item): bool
    {
        return $this->may($item->type, 'edit_any') || $this->may($item->type, 'edit_own', $item);
    }

    public function mayTransition(Content $item, string $transition): ?string
    {
        if (\in_array($transition, self::PUBLISHING, true)) {
            return $this->may($item->type, 'publish') ? null : 'Publishing takes the right to publish; ask someone who has it.';
        }

        return $this->mayEdit($item) ? null : 'You may not change this item.';
    }

    public function isOwn(PendingRevision $pending): bool
    {
        return null !== $pending->authorId && $pending->authorId === $this->actorId();
    }

    public function actorId(): ?string
    {
        return $this->users->get()?->id;
    }
}
