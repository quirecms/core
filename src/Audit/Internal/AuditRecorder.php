<?php

declare(strict_types=1);

namespace Quire\Audit\Internal;

use Quire\Action\Actions;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Content\Content;
use Quire\Content\Event\ContentCreated;
use Quire\Content\Event\ContentDeleted;
use Quire\Content\Event\ContentPurged;
use Quire\Content\Event\ContentRestored;
use Quire\Content\Event\ContentStatusChanged;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Event\PendingRevisionCommented;
use Quire\Content\Event\PendingRevisionCommentResolved;
use Quire\Content\Event\PendingRevisionDiscarded;
use Quire\Content\Event\PendingRevisionPublishFailed;
use Quire\Content\Event\PendingRevisionReviewed;
use Quire\Content\Event\PendingRevisionSaved;
use Quire\Content\Event\PendingRevisionScheduled;
use Quire\Content\Event\PendingRevisionSubmitted;
use Quire\Content\Internal\Fields;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Extension\Event\ExtensionDisabled;
use Quire\Extension\Event\ExtensionEnabled;
use Quire\Extension\Event\ExtensionPurging;
use Quire\Permission\Permissions;
use Quire\Settings\Event\SettingChanged;
use Quire\Settings\SettingDefinition;
use Quire\Settings\SettingsSchema;
use Quire\Theme\Themes;
use Quire\User\Event\UserCreated;
use Quire\User\Event\UserDeleted;
use Quire\User\Event\UserInvited;
use Quire\User\Event\UserLoggedIn;
use Quire\User\Event\UserRegistered;
use Quire\User\Event\UserUpdated;
use Quire\User\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records core's actions in the audit log, from core's events, with the console command that does the
 * same where there is one: the event is written as a call of the action that has the same effect (see
 * {@see Actions}), which knows its command. Listeners run after the change was made (for content and
 * users, after it was committed), so they see changes however they were made.
 *
 * @internal
 */
final readonly class AuditRecorder
{
    public function __construct(
        private AuditLog $log,
        private SettingsSchema $schema,
        private Permissions $permissions,
        private Actions $actions,
        private ContentTypeRegistry $types,
    ) {
    }

    #[AsEventListener]
    public function onContentCreated(ContentCreated $event): void
    {
        $content = $event->content;
        // The command creates an empty draft: items created with text or published at once have none.
        $command = Status::DRAFT === $content->status && '' === $content->body && $content->fields === Fields::withDefaults($this->types->get($content->type), [])
            ? $this->command('content.create', ['type' => $content->type, 'title' => $content->title, 'slug' => $content->slug, 'locale' => $content->locale, 'parent' => $content->parentId, 'author' => $content->authorId])
            : null;

        $this->content('content.created', $content, ['type' => $content->type, 'status' => $content->status, 'locale' => $content->locale], $command);
    }

    #[AsEventListener]
    public function onContentUpdated(ContentUpdated $event): void
    {
        $details = ['changed' => $this->changed($event->before, $event->content), 'version' => $event->content->version];
        $pending = $event->publishedPendingRevision;

        if ($pending instanceof PendingRevision) {
            // In the background (quire.publish-due), on behalf of who scheduled it.
            $this->content('content.changes_published', $event->content, [...$details, 'pending_revision' => $pending->id], $this->command('content.publish_changes', ['content' => $pending->contentId, 'pending_revision' => $pending->id]), $pending->scheduledBy);

            return;
        }

        // Renamed only: the text and fields are written in an editor, not on a command line.
        $renamed = array_intersect_key(['title' => $event->content->title, 'slug' => $event->content->slug, 'excerpt' => $event->content->excerpt], array_flip($details['changed']));
        $command = [] !== $renamed && \count($renamed) === \count($details['changed']) && !\in_array(null, $renamed, true)
            ? $this->command('content.update', ['content' => $event->content->id, ...$renamed])
            : null;

        $this->content('content.updated', $event->content, $details, $command, coalesce: true);
    }

    #[AsEventListener]
    public function onContentStatusChanged(ContentStatusChanged $event): void
    {
        $content = $event->content;
        $at = Status::SCHEDULE === $event->transition ? $content->publishedAt?->format(\DATE_ATOM) : null;

        $this->content('content.transitioned', $content, [
            'transition' => $event->transition,
            'from' => $event->before->status,
            'to' => $content->status,
            ...null === $at ? [] : ['at' => $at],
        ], $this->command('content.transition', ['content' => $content->id, 'transition' => $event->transition, 'at' => $at]));
    }

    #[AsEventListener]
    public function onContentDeleted(ContentDeleted $event): void
    {
        $this->content('content.trashed', $event->content, [], $this->command('content.trash', ['content' => $event->content->id]));
    }

    #[AsEventListener]
    public function onContentRestored(ContentRestored $event): void
    {
        $this->content('content.restored', $event->content, [], $this->command('content.restore', ['content' => $event->content->id]));
    }

    #[AsEventListener]
    public function onContentPurged(ContentPurged $event): void
    {
        $this->content('content.purged', $event->content, [], $this->command('content.purge', ['content' => $event->content->id]));
    }

    #[AsEventListener]
    public function onPendingSaved(PendingRevisionSaved $event): void
    {
        // Saved every few seconds while someone writes: one entry per writing session.
        $this->pending('content.changes_saved', $event->pendingRevision, $event->pendingRevision->authorId, coalesce: true);
    }

    #[AsEventListener]
    public function onPendingSubmitted(PendingRevisionSubmitted $event): void
    {
        $pending = $event->pendingRevision;

        $this->pending('content.changes_submitted', $pending, $event->actorId, [], $this->command('content.submit_changes', ['content' => $pending->contentId, 'pending_revision' => $pending->id]));
    }

    #[AsEventListener]
    public function onPendingReviewed(PendingRevisionReviewed $event): void
    {
        $pending = $event->pendingRevision;
        $review = $event->review;

        $this->pending('content.changes_reviewed', $pending, $review->reviewerId, ['verdict' => $review->verdict->value], $this->command('content.review_changes', [
            'content' => $pending->contentId,
            'pending_revision' => $pending->id,
            'verdict' => $review->verdict->value,
            'message' => $review->message,
        ]));
    }

    #[AsEventListener]
    public function onPendingScheduled(PendingRevisionScheduled $event): void
    {
        $pending = $event->pendingRevision;
        $at = $pending->publishAt?->format(\DATE_ATOM);

        $this->pending('content.changes_scheduled', $pending, $event->actorId, ['at' => $at], $this->command('content.schedule_changes', ['content' => $pending->contentId, 'pending_revision' => $pending->id, 'at' => $at]));
    }

    #[AsEventListener]
    public function onPendingDiscarded(PendingRevisionDiscarded $event): void
    {
        $pending = $event->pendingRevision;

        $this->pending('content.changes_discarded', $pending, $event->actorId, [], $this->command('content.discard_changes', ['content' => $pending->contentId, 'pending_revision' => $pending->id]));
    }

    #[AsEventListener]
    public function onPendingCommented(PendingRevisionCommented $event): void
    {
        $pending = $event->pendingRevision;
        $comment = $event->comment;
        $anchor = $comment->anchor;

        // The command has the comment, as a review's has its message, so it can be made again.
        $this->pending('content.changes_commented', $pending, $comment->authorId, array_filter([
            'comment' => $comment->id,
            'reply_to' => $comment->parentId,
            'on' => $anchor?->property,
        ], \is_string(...)), $this->command('content.comment_changes', [
            'content' => $pending->contentId,
            'pending_revision' => $pending->id,
            'message' => $comment->body,
            'property' => $anchor?->property,
            'quote' => $anchor?->quote,
            'line' => $anchor?->line,
            'reply_to' => $comment->parentId,
        ]));
    }

    #[AsEventListener]
    public function onCommentResolved(PendingRevisionCommentResolved $event): void
    {
        $pending = $event->pendingRevision;
        $resolved = $event->comment->isResolved();

        $this->pending($resolved ? 'content.comment_resolved' : 'content.comment_reopened', $pending, $event->actorId, ['comment' => $event->comment->id], $this->command('content.resolve_comment', [
            'content' => $pending->contentId,
            'pending_revision' => $pending->id,
            'comment' => $event->comment->id,
            'reopen' => $resolved ? null : true,
        ]));
    }

    #[AsEventListener]
    public function onPendingPublishFailed(PendingRevisionPublishFailed $event): void
    {
        $this->pending('content.changes_publish_failed', $event->pendingRevision, $event->pendingRevision->scheduledBy, ['reason' => $event->reason]);
    }

    #[AsEventListener]
    public function onUserCreated(UserCreated $event): void
    {
        $user = $event->user;

        // The password stays out of it: the command asks for one.
        $command = null === $user->email ? null : $this->command('user.create', [
            'email' => $user->email,
            'name' => $user->displayName,
            'username' => $user->username,
            'roles' => $user->roles,
            'password' => null === $user->passwordHash ? null : '********',
        ]);

        $this->user('user.created', $user, ['roles' => $user->roles], $command);
    }

    #[AsEventListener]
    public function onUserRegistered(UserRegistered $event): void
    {
        $created = $this->recordedFor('user', $event->user->id, 'user.created');

        // Created a moment ago, by the person who registered.
        $created instanceof AuditEntry
            ? $this->log->amend($created, 'user.registered', actorId: $event->user->id)
            : $this->user('user.registered', $event->user, actorId: $event->user->id);
    }

    #[AsEventListener]
    public function onUserUpdated(UserUpdated $event): void
    {
        $before = $event->before;
        $user = $event->user;
        $username = $user->username;

        $profile = array_keys(array_filter([
            'display_name' => $before->displayName !== $user->displayName,
            'email' => $before->email !== $user->email,
            'username' => $before->username !== $user->username,
            'attributes' => $before->attributes !== $user->attributes,
        ]));

        if ([] !== $profile) {
            // Which, not the values: the log isn't another copy of personal data (nor is a command with them).
            $this->user('user.updated', $user, ['changed' => $profile]);
        }

        $added = array_values(array_diff($user->roles, $before->roles));
        $removed = array_values(array_diff($before->roles, $user->roles));

        if ([] !== $added || [] !== $removed) {
            $this->user('user.roles_changed', $user, ['added' => $added, 'removed' => $removed], $this->command('user.change_roles', ['user' => $username, 'add' => $added, 'remove' => $removed]));
        }

        if ($before->status !== $user->status) {
            $suspended = User::SUSPENDED === $user->status;
            $this->user($suspended ? 'user.suspended' : 'user.activated', $user, [], $this->command($suspended ? 'user.suspend' : 'user.activate', ['user' => $username]));
        }

        if ($before->passwordHash !== $user->passwordHash && null !== $before->passwordHash && null === $user->passwordHash) {
            $this->user('user.password_removed', $user, [], $this->command('user.remove_password', ['user' => $username]));
        } elseif ($before->passwordHash !== $user->passwordHash && null !== $user->passwordHash) {
            // The user's own reset or invitation, an admin, or the command line; without the password.
            $this->user('user.password_changed', $user, [], $this->command('user.set_password', ['user' => $username, 'password' => '********']));
        }
    }

    #[AsEventListener]
    public function onUserDeleted(UserDeleted $event): void
    {
        $user = $event->user;
        $heir = $event->reassignTo;

        $this->user('user.deleted', $user, $heir instanceof User ? ['reassigned_to' => $heir->displayName] : [], $this->command('user.delete', ['user' => $user->username, 'reassign' => $heir?->username]));
    }

    #[AsEventListener]
    public function onUserInvited(UserInvited $event): void
    {
        $this->user('user.invited', $event->user, [], $this->command('user.invite', ['users' => [$event->user->username]]), $event->by?->id);
    }

    #[AsEventListener]
    public function onLoggedIn(UserLoggedIn $event): void
    {
        $this->user('user.signed_in', $event->user, ['firewall' => $event->firewall], actorId: $event->user->id);
    }

    #[AsEventListener]
    public function onExtensionEnabled(ExtensionEnabled $event): void
    {
        $extension = $event->extension;

        $this->log->record('extension.enabled', 'extension', $extension->id, $extension->title, ['version' => $extension->version], $this->command('extension.enable', ['extensions' => [$extension->id]]));
    }

    #[AsEventListener]
    public function onExtensionPurging(ExtensionPurging $event): void
    {
        $extension = $event->extension;

        $this->log->record('extension.purged', 'extension', $extension->id, $extension->title, ['version' => $extension->version], $this->command('extension.purge', ['extensions' => [$extension->id]]));
    }

    #[AsEventListener]
    public function onExtensionDisabled(ExtensionDisabled $event): void
    {
        // Purged just before: that entry says it was disabled too.
        if ($this->recordedFor('extension', $event->id, 'extension.purged') instanceof AuditEntry) {
            return;
        }

        $this->log->record('extension.disabled', 'extension', $event->id, $event->extension->title ?? $event->id, [], $this->command('extension.disable', ['extensions' => [$event->id]]));
    }

    #[AsEventListener]
    public function onSettingChanged(SettingChanged $event): void
    {
        match ($event->key) {
            Permissions::SETTING => $this->grantsChanged($event->oldValue, $event->newValue),
            Permissions::ROLES_SETTING => $this->rolesChanged($event->oldValue, $event->newValue),
            Themes::SETTING => $this->log->record('theme.changed', 'theme', \is_string($event->newValue) ? $event->newValue : null, \is_string($event->newValue) ? $event->newValue : null, [
                'from' => $event->oldValue,
                'to' => $event->newValue,
            ], $this->command('theme.use', ['theme' => \is_string($event->newValue) && '' !== $event->newValue ? $event->newValue : null])),
            default => $this->settingChanged($event),
        };
    }

    private function settingChanged(SettingChanged $event): void
    {
        $definition = $this->schema->has($event->key) ? $this->schema->get($event->key) : null;

        if (!$definition instanceof SettingDefinition || $definition->sensitive) {
            // Neither the value nor a command with it: only that it changed.
            $this->log->record('setting.changed', 'setting', $event->key, $definition->label ?? $event->key, ['sensitive' => true]);

            return;
        }

        $this->log->record('setting.changed', 'setting', $event->key, $definition->label ?? $event->key, [
            'from' => $event->oldValue,
            'to' => $event->newValue,
        ], $this->command('setting.set', ['setting' => $event->key, 'value' => $event->newValue]));
    }

    private function grantsChanged(mixed $old, mixed $new): void
    {
        $before = $this->permissions->grantsFrom($old);
        $after = $this->permissions->grantsFrom($new);
        $changes = [];

        foreach ($after as $ability => $roles) {
            foreach (array_diff($roles, $before[$ability] ?? []) as $role) {
                $changes[$role]['granted'][] = $ability;
            }

            foreach (array_diff($before[$ability] ?? [], $roles) as $role) {
                $changes[$role]['revoked'][] = $ability;
            }
        }

        $labels = $this->permissions->roles();
        ksort($changes);

        foreach ($changes as $role => $change) {
            $granted = $change['granted'] ?? [];
            $revoked = $change['revoked'] ?? [];
            $commands = array_filter([
                [] === $granted ? null : $this->command('role.grant', ['role' => $role, 'abilities' => $granted]),
                [] === $revoked ? null : $this->command('role.revoke', ['role' => $role, 'abilities' => $revoked]),
            ], \is_string(...));

            $this->log->record('role.abilities_changed', 'role', $role, $labels[$role] ?? $role, ['granted' => $granted, 'revoked' => $revoked], [] === $commands ? null : implode("\n", $commands));
        }
    }

    private function rolesChanged(mixed $old, mixed $new): void
    {
        $before = $this->labels($old);
        $after = $this->labels($new);

        foreach ($after as $name => $label) {
            if (!isset($before[$name])) {
                $this->log->record('role.created', 'role', $name, $label, [], $this->command('role.create', ['role' => $name, 'label' => $label]));
            } elseif ($before[$name] !== $label) {
                $this->log->record('role.renamed', 'role', $name, $label, ['from' => $before[$name], 'to' => $label], $this->command('role.rename', ['role' => $name, 'label' => $label]));
            }
        }

        foreach (array_diff_key($before, $after) as $name => $label) {
            $command = $this->command('role.delete', ['role' => $name]);
            // Its grants were forgotten a moment ago, as part of deleting it.
            $grants = $this->recordedFor('role', $name, 'role.abilities_changed');

            $grants instanceof AuditEntry
                ? $this->log->amend($grants, 'role.deleted', command: $command)
                : $this->log->record('role.deleted', 'role', $name, $label, [], $command);
        }
    }

    /**
     * @return array<string, string>
     */
    private function labels(mixed $value): array
    {
        return \is_array($value) ? array_filter(array_filter($value, \is_string(...)), \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * The console command of the action that does what the event says was done.
     *
     * @param array<string, mixed> $arguments
     */
    private function command(string $action, array $arguments): ?string
    {
        try {
            return $this->actions->commandLine($action, $arguments);
        } catch (InvalidArgumentsException) {
            // E.g. a username that isn't one any more: better no command than a wrong one, or none of the entry.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function content(string $action, Content $content, array $details = [], ?string $command = null, ?string $actorId = null, bool $coalesce = false): void
    {
        $this->log->record($action, 'content', $content->id, $content->title, ['type' => $content->type, ...$details], $command, $actorId, $coalesce);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function pending(string $action, PendingRevision $pending, ?string $actorId, array $details = [], ?string $command = null, bool $coalesce = false): void
    {
        $this->log->record($action, 'content', $pending->contentId, $pending->proposed->title, ['pending_revision' => $pending->id, ...$details], $command, $actorId, $coalesce);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function user(string $action, User $user, array $details = [], ?string $command = null, ?string $actorId = null): void
    {
        $this->log->record($action, 'user', $user->id, $user->displayName, $details, $command, $actorId);
    }

    private function recordedFor(string $subjectType, string $subjectId, string $action): ?AuditEntry
    {
        return array_find(
            array_reverse($this->log->recordedNow()),
            static fn (AuditEntry $entry): bool => $entry->subjectType === $subjectType && $entry->subjectId === $subjectId && $entry->action === $action,
        );
    }

    /**
     * @return list<string>
     */
    private function changed(Content $before, Content $after): array
    {
        $changed = array_keys(array_filter([
            'title' => $before->title !== $after->title,
            'slug' => $before->slug !== $after->slug,
            'body' => $before->body !== $after->body || $before->bodyFormat !== $after->bodyFormat,
            'excerpt' => $before->excerpt !== $after->excerpt,
            'parent' => $before->parentId !== $after->parentId,
            'position' => $before->position !== $after->position,
            'author' => $before->authorId !== $after->authorId,
            'published_at' => $before->publishedAt?->getTimestamp() !== $after->publishedAt?->getTimestamp(),
        ]));

        foreach (array_keys($before->fields + $after->fields) as $field) {
            if (($before->fields[$field] ?? null) !== ($after->fields[$field] ?? null)) {
                $changed[] = 'fields.'.$field;
            }
        }

        return $changed;
    }
}
