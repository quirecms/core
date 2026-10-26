<?php

declare(strict_types=1);

namespace Quire\Permission;

use Quire\Audit\AuditLog;
use Quire\User\CurrentUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Seeing the site and the admin as another user sees them, to check what they can do, without being
 * able to change anything: while it's on, each request runs as them, and requests that would change
 * something are refused. Only someone who may do everything the other user may do can see as them,
 * so it never shows more than they could see anyway.
 *
 * @api
 */
final readonly class ViewAs
{
    public const string ABILITY = 'quire.users.view_as';

    /** A route default that lets a request through while seeing as someone, e.g. the one that stops it */
    public const string ALLOWED_ROUTE = '_quire_view_as_allowed';

    private const string SESSION_KEY = '_quire_view_as';

    public function __construct(
        private RequestStack $requests,
        private Security $security,
        private CurrentUser $users,
        private UserRepository $repository,
        private Permissions $permissions,
        private AuditLog $audit,
    ) {
    }

    /**
     * Why the signed-in user can't see as the other user, or null when they can.
     */
    public function refusal(User $target): ?string
    {
        $viewer = $this->viewer() ?? $this->users->get();

        return match (true) {
            !$viewer instanceof User || !$this->security->isGranted(self::ABILITY) => 'Seeing the site as someone else takes the ability "'.self::ABILITY.'".',
            $viewer->id === $target->id => 'That\'s you.',
            !$target->isActive() => 'Suspended users can\'t sign in, so there\'s nothing to see as them.',
            [] !== $this->beyond($viewer, $target) => \sprintf('They may do what you may not (%s), so you can\'t see as them.', implode(', ', \array_slice($this->beyond($viewer, $target), 0, 3))),
            default => null,
        };
    }

    /**
     * @throws \LogicException when the user can't see as them; ask {@see refusal()} first
     */
    public function start(User $target): void
    {
        $refusal = $this->refusal($target);
        $viewer = $this->viewer() ?? $this->users->get();

        if (null !== $refusal || !$viewer instanceof User) {
            throw new \LogicException($refusal ?? 'Nobody is signed in.');
        }

        $this->requests->getSession()->set(self::SESSION_KEY, ['viewer' => $viewer->id, 'target' => $target->id]);
        $this->audit->record('user.viewed_as', 'user', $target->id, $target->displayName);
    }

    public function stop(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * Whom the site is seen as, while it is.
     */
    public function target(): ?User
    {
        $ids = $this->ids();

        return null === $ids ? null : $this->repository->find($ids['target']);
    }

    /**
     * Who is seeing the site as someone else, while they are.
     */
    public function viewer(): ?User
    {
        $ids = $this->ids();

        return null === $ids ? null : $this->repository->find($ids['viewer']);
    }

    /**
     * @internal For the listener: the IDs while it's on
     *
     * @return array{viewer: string, target: string}|null
     */
    public function ids(): ?array
    {
        $request = $this->requests->getMainRequest();

        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || !$request->hasPreviousSession()) {
            return null;
        }

        $ids = $request->getSession()->get(self::SESSION_KEY);

        return \is_array($ids) && \is_string($ids['viewer'] ?? null) && \is_string($ids['target'] ?? null) ? ['viewer' => $ids['viewer'], 'target' => $ids['target']] : null;
    }

    /**
     * The abilities the target's roles give and the viewer's don't.
     *
     * @return list<string>
     */
    private function beyond(User $viewer, User $target): array
    {
        return array_values(array_filter(
            array_keys($this->permissions->abilities()),
            fn (string $ability): bool => $this->permissions->allows($target->roles, $ability) && !$this->permissions->allows($viewer->roles, $ability),
        ));
    }
}
