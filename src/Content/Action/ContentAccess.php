<?php

declare(strict_types=1);

namespace Quire\Content\Action;

use Quire\Content\Content;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Status;
use Quire\User\CurrentUser;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may do what with content: the abilities "content.<type>.<verb>". Every question about an item
 * passes the item, so a rule (Quire\Permission\AbilityRuleInterface) decides on it, e.g. "nobody edits a
 * locked item" (D-42, H-4). Questions about a type, such as "may they open its list?", have no item.
 *
 * Content's actions and the admin ask here, so there's one place that passes the item.
 *
 * @api
 */
final readonly class ContentAccess
{
    /** Transitions that change what visitors see, so they take the right to publish */
    public const array PUBLISHING = [Status::PUBLISH, Status::SCHEDULE, Status::UNPUBLISH, Status::ARCHIVE];

    public function __construct(
        private AuthorizationCheckerInterface $authorization,
        private CurrentUser $users,
    ) {
    }

    /**
     * An ability of a type, for the item when there is one.
     */
    public function may(string $type, string $verb, ?Content $item = null): bool
    {
        return $this->authorization->isGranted(\sprintf('content.%s.%s', $type, $verb), $item);
    }

    /**
     * Whether they may change the item: any item of its type, or their own.
     */
    public function mayEdit(Content $item): bool
    {
        return $this->may($item->type, 'edit_any', $item) || $this->may($item->type, 'edit_own', $item);
    }

    public function mayPublish(Content $item): bool
    {
        return $this->may($item->type, 'publish', $item);
    }

    public function mayReview(Content $item): bool
    {
        return $this->may($item->type, 'review', $item);
    }

    /**
     * Moving it to the trash, restoring it and deleting it permanently.
     */
    public function mayDelete(Content $item): bool
    {
        return $this->may($item->type, 'delete', $item);
    }

    /**
     * Why they may not take the transition on the item, or null when they may.
     */
    public function mayTransition(Content $item, string $transition): ?string
    {
        if (\in_array($transition, self::PUBLISHING, true)) {
            return $this->mayPublish($item) ? null : 'Publishing takes the right to publish; ask someone who has it.';
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
