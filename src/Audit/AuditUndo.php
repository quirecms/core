<?php

declare(strict_types=1);

namespace Quire\Audit;

use Quire\Action\Actions;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Workflow\ContentWorkflow;
use Quire\Permission\Permissions;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Themes;
use Quire\User\User;
use Quire\User\UserRepository;

/**
 * Undoes what an entry of the audit log records, through the action that does the opposite: publishing
 * through the transition back, moving to the trash through restoring, suspending through letting in
 * again, roles, abilities and settings back to what they were. Only while what the entry changed is
 * still as it left it, so undoing never overwrites what someone did since; and never what can't be
 * taken back: deletions, purges, emails, sign-ins, extensions.
 *
 * The undo is an action like any other: it takes its ability, and it's recorded in the audit log with
 * its command.
 *
 * @api
 */
final readonly class AuditUndo
{
    /** What can be undone, by its action in the log */
    /** What doesn't count as coming after an entry: signing in changes nothing */
    private const array NOT_COUNTED = ['user.signed_in'];

    private const array UNDOABLE = ['content.transitioned', 'content.trashed', 'content.restored', 'user.suspended', 'user.activated', 'user.roles_changed', 'role.abilities_changed', 'role.renamed', 'setting.changed', 'theme.changed'];

    public function __construct(
        private Actions $actions,
        private ContentRepository $content,
        private ContentWorkflow $workflow,
        private UserRepository $users,
        private Permissions $permissions,
        private Settings $settings,
        private SettingsSchema $schema,
        private AuditLog $log,
    ) {
    }

    /**
     * How the entry would be undone now, or null when it can't be: what it changed is no longer as it left it,
     * or it can't be taken back.
     */
    public function plan(AuditEntry $entry): ?UndoPlan
    {
        if (!$this->mayBeUndone($entry)) {
            return null;
        }

        $latest = $this->log->find(new AuditQuery(subjectType: $entry->subjectType, subjectId: $entry->subjectId, exceptActions: self::NOT_COUNTED), perPage: 1)->entries[0] ?? null;
        $item = 'content' === $entry->subjectType && null !== $entry->subjectId ? $this->content->find($entry->subjectId) : null;
        $user = 'user' === $entry->subjectType && null !== $entry->subjectId ? $this->users->find($entry->subjectId) : null;

        return $this->planWith($entry, $latest?->id, $item, $user);
    }

    /**
     * Which of the entries the signed-in person may undo now: {@see canUndo()} for a page of entries, in
     * a few queries for all of them rather than a few for each.
     *
     * @param iterable<AuditEntry> $entries
     *
     * @return array<string, true> By the entry's ID
     */
    public function undoable(iterable $entries): array
    {
        $entries = array_values(array_filter([...$entries], $this->mayBeUndone(...)));
        $about = array_values(array_filter($entries, static fn (AuditEntry $entry): bool => null !== $entry->subjectId));
        $latest = $this->log->latestAbout(array_map(static fn (AuditEntry $entry): array => [(string) $entry->subjectType, (string) $entry->subjectId], $about), self::NOT_COUNTED);
        $subjects = static fn (string $type): array => array_map(static fn (AuditEntry $entry): string => (string) $entry->subjectId, array_filter($about, static fn (AuditEntry $entry): bool => $type === $entry->subjectType));
        $items = $this->content->findMany($subjects('content'));
        $users = $this->users->findMany($subjects('user'));
        $undoable = [];

        foreach ($entries as $entry) {
            $plan = null === $entry->subjectId
                ? $this->plan($entry)
                : $this->planWith($entry, $latest[$entry->subjectType.' '.$entry->subjectId] ?? null, $items[$entry->subjectId] ?? null, $users[$entry->subjectId] ?? null);

            if ($plan instanceof UndoPlan && null === $this->actions->denied($plan->action, $plan->arguments)) {
                $undoable[$entry->id] = true;
            }
        }

        return $undoable;
    }

    private function mayBeUndone(AuditEntry $entry): bool
    {
        return \in_array($entry->action, self::UNDOABLE, true) && null !== $entry->subjectType;
    }

    /**
     * @param string|null $latestId The latest entry about the same subject
     */
    private function planWith(AuditEntry $entry, ?string $latestId, ?Content $item, ?User $user): ?UndoPlan
    {
        // Only the latest about its subject: what came after built on it.
        if ($latestId !== $entry->id) {
            return null;
        }

        $details = $entry->details;
        $from = \is_string($details['from'] ?? null) ? $details['from'] : null;
        $to = \is_string($details['to'] ?? null) ? $details['to'] : null;
        $role = 'role' === $entry->subjectType ? $entry->subjectId : null;

        return match ($entry->action) {
            'content.transitioned' => $item instanceof Content && !$item->isTrashed() && null !== $from && $item->status === $to
                ? $this->transitionBack($item, $from)
                : null,
            'content.trashed' => $item instanceof Content && $item->isTrashed() ? new UndoPlan('content.restore', ['content' => $item->id]) : null,
            'content.restored' => $item instanceof Content && !$item->isTrashed() ? new UndoPlan('content.trash', ['content' => $item->id]) : null,
            'user.suspended' => $user instanceof User && !$user->isActive() ? new UndoPlan('user.activate', ['user' => $user->id]) : null,
            'user.activated' => $user instanceof User && $user->isActive() ? new UndoPlan('user.suspend', ['user' => $user->id]) : null,
            'user.roles_changed' => $user instanceof User ? $this->rolesBack($user, $this->strings($details['added'] ?? []), $this->strings($details['removed'] ?? [])) : null,
            'role.abilities_changed' => null !== $role && $this->permissions->hasRole($role) ? $this->abilitiesBack($role, $this->strings($details['granted'] ?? []), $this->strings($details['revoked'] ?? [])) : null,
            'role.renamed' => null !== $role && null !== $from && ($this->permissions->roles()[$role] ?? null) === $to ? new UndoPlan('role.rename', ['role' => $role, 'label' => $from]) : null,
            'setting.changed' => $this->settingBack($entry),
            // The theme.
            default => $this->settings->get(Themes::SETTING) === ($details['to'] ?? null) ? new UndoPlan('theme.use', ['theme' => $from]) : null,
        };
    }

    /**
     * Whether the signed-in person may undo the entry now.
     */
    public function canUndo(AuditEntry $entry): bool
    {
        $plan = $this->plan($entry);

        return $plan instanceof UndoPlan && null === $this->actions->denied($plan->action, $plan->arguments);
    }

    /**
     * @throws \LogicException                               when the entry can't be undone now
     * @throws \Quire\Action\Exception\ActionDeniedException when the person may not
     */
    public function undo(AuditEntry $entry): UndoPlan
    {
        $plan = $this->plan($entry) ?? throw new \LogicException('This can\'t be undone: what it changed was changed again since, or it can\'t be taken back.');
        $this->actions->run($plan->action, $plan->arguments);

        return $plan;
    }

    private function transitionBack(Content $item, string $from): ?UndoPlan
    {
        $transition = $this->workflow->transitionBetween($item->status, $from);

        return null === $transition ? null : new UndoPlan('content.transition', ['content' => $item->id, 'transition' => $transition]);
    }

    /**
     * @param list<string> $added
     * @param list<string> $removed
     */
    private function rolesBack(User $user, array $added, array $removed): ?UndoPlan
    {
        $still = [] === array_diff($added, $user->roles) && [] === array_intersect($removed, $user->roles);

        return $still && ([] !== $added || [] !== $removed) ? new UndoPlan('user.change_roles', ['user' => $user->id, 'add' => $removed, 'remove' => $added]) : null;
    }

    /**
     * @param list<string> $granted
     * @param list<string> $revoked
     */
    private function abilitiesBack(string $role, array $granted, array $revoked): ?UndoPlan
    {
        $has = fn (string $ability): bool => $this->permissions->has($ability) && \in_array($role, $this->permissions->rolesWith($ability), true);

        // Both ways at once would take two actions: only one way is undone in one go.
        return match (true) {
            [] !== $granted && [] === $revoked && array_all($granted, $has) => new UndoPlan('role.revoke', ['role' => $role, 'abilities' => $granted]),
            [] !== $revoked && [] === $granted && !array_any($revoked, $has) => new UndoPlan('role.grant', ['role' => $role, 'abilities' => $revoked]),
            default => null,
        };
    }

    private function settingBack(AuditEntry $entry): ?UndoPlan
    {
        $key = $entry->subjectId;

        if (null === $key || true === ($entry->details['sensitive'] ?? false) || !\array_key_exists('from', $entry->details) || !$this->schema->has($key)) {
            return null;
        }

        return $this->settings->get($key) === ($entry->details['to'] ?? null) ? new UndoPlan('setting.set', ['setting' => $key, 'value' => $entry->details['from']]) : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }
}
