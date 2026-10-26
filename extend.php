<?php

declare(strict_types=1);

use Quire\Audit\AuditLog;
use Quire\Audit\Job\PruneAuditLog;
use Quire\Content\Action as ContentAction;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Internal\AltTextCheck;
use Quire\Content\Check\Internal\ExcerptCheck;
use Quire\Content\Check\Internal\LinksCheck;
use Quire\Content\Check\Internal\TitleLengthCheck;
use Quire\Content\Check\Job\RecheckContent;
use Quire\Content\ContentWriter;
use Quire\Content\Job\PublishDueContent;
use Quire\Content\Query\Relation;
use Quire\Content\Status;
use Quire\Content\Type\Capability;
use Quire\Extend;
use Quire\Extension\Action as ExtensionAction;
use Quire\Formatter\Format;
use Quire\Permission\Action as PermissionAction;
use Quire\Permission\Permissions;
use Quire\Routing\AdminAccessListener;
use Quire\Settings\Action\SetSetting;
use Quire\Storage\Job\CollectGarbage;
use Quire\Storage\Job\CollectGarbageHandler;
use Quire\Theme\Action\UseTheme;
use Quire\Theme\Internal\AdminAssetsSlot;
use Quire\Theme\Internal\AssetsSlot;
use Quire\Theme\Seo\SeoRenderer;
use Quire\Theme\Themes;
use Quire\User\AccountSettings;
use Quire\User\Action as UserAction;
use Quire\User\Role;
use Quire\User\Security\SignInListener;

/*
 * Quire core uses the same extenders as extensions. This file runs first, before any extension.
 */
return [
    new Extend\Migrations('Quire\\Migrations', __DIR__.'/migrations'),

    // The stylesheets and scripts of Extend\Assets, before other fragments of the "head" slot.
    new Extend\Views()
        ->slot('head', AssetsSlot::class, priority: 1000)
        ->slot('admin.head', AdminAssetsSlot::class, priority: 1000),

    // Checks before publishing (see docs/content.md): warnings until a site makes them errors.
    new Extend\ContentChecks()
        ->check('core.alt-text', AltTextCheck::class, 'Images have a description')
        ->check('core.links', LinksCheck::class, 'Links to the site lead to pages visitors can see')
        ->check('core.excerpt', ExcerptCheck::class, 'Items have an excerpt')
        ->check('core.title-length', TitleLengthCheck::class, 'Titles fit in search results'),

    new Extend\Settings()
        ->define('site.title', 'string', 'Quire', label: 'Site title', help: 'Shown in the browser tab and in the site header.', group: 'site', exposed: true)
        ->define('site.description', 'string', '', label: 'Site description', help: 'A short description of the site, used by search engines and when the site is shared.', group: 'site', widget: 'textarea', exposed: true)
        ->define(SeoRenderer::SHARE_IMAGE_SETTING, 'string', null, label: 'Share image', help: 'The image shown when a page without one of its own is shared, e.g. on social networks: a URL, or a path on the site such as "/images/share.png". 1200 × 630 pixels works everywhere.', group: 'site', nullable: true)
        ->define('site.locale', 'string', 'en', label: 'Language', help: 'The language of the site and of new content, as a language tag such as "en", "de" or "pt-BR".', group: 'site', exposed: true)
        ->define('site.timezone', 'enum', 'UTC', label: 'Time zone', help: 'Where the site is, e.g. "Europe/Berlin": editors enter and see dates and times in it.', group: 'site', choices: DateTimeZone::listIdentifiers(), exposed: true)
        ->define(ContentWriter::APPROVAL_SETTING, 'bool', false, label: 'Approve changes', help: 'Whether changes to published content must be approved by a reviewer before they go live. Applies to changes saved as pending revisions, e.g. in the admin.', group: 'content')
        ->define(ContentChecker::LEVELS_SETTING, 'json', [], label: 'Content checks', help: 'The level of each check of content before it\'s published, where it isn\'t the default: "error" (blocks publishing), "warning" or "off", e.g. {"core.alt-text": "error"}. List the checks with "bin/console quire:content:check --list".', group: 'content')
        ->define('quire.content.revisions_keep', 'int', 50, label: 'Revisions to keep', help: 'How many revisions to keep per content item. Older ones are deleted. 0 keeps all.', group: 'content')
        ->define(CollectGarbageHandler::GRACE_SETTING, 'int', 1, label: 'Keep unused files', help: 'For how many days the daily clean-up keeps stored files that nothing uses any more (at least 1). Make it at least as long as you keep backups that don\'t contain the files.', group: 'storage')
        ->define('quire.content.per_page', 'int', 10, label: 'Items per page', help: 'How many items lists such as the blog show per page (1 to 100).', group: 'content')
        // What the homepage shows. The URL patterns of each content type are settings too: quire.permalinks.<type>.
        ->define('site.homepage', 'enum', 'listing', label: 'Homepage', help: 'What the homepage shows: the list of a content type (see "Homepage list"), a page (see "Homepage page"), or a page of an extension (see "Homepage route").', group: 'site', choices: ['listing', 'page', 'route'])
        ->define('site.homepage_listing', 'string', 'post', label: 'Homepage list', help: 'The content type whose list the homepage shows, e.g. "post".', group: 'site')
        ->define('site.homepage_page', 'string', null, label: 'Homepage page', help: 'The page the homepage shows: its ID or its path, e.g. "home".', group: 'site', nullable: true)
        ->define('site.homepage_route', 'string', null, label: 'Homepage route', help: 'The name of the extension route the homepage shows. It must not need parameters.', group: 'site', nullable: true)
        ->define(Permissions::SETTING, 'json', [], label: 'Permission changes', help: 'Abilities granted to or revoked from roles, on top of the defaults. Change them with "bin/console quire:permissions:grant" and "revoke".', group: 'users')
        ->define(Permissions::ROLES_SETTING, 'json', [], label: 'Site roles', help: 'Roles the site added, by name, with their labels. Change them with "bin/console quire:permissions:create-role" and "delete-role", or in the admin.', group: 'users')
        ->define(AccountSettings::REGISTRATION, 'bool', false, label: 'Registration', help: 'Whether visitors can create an account on the site, as members. Emails need a mailer (MAILER_DSN).', group: 'users')
        ->define(AccountSettings::EMAIL_VERIFICATION, 'bool', false, label: 'Confirm emails', help: 'Whether people who register confirm their email, by opening an emailed link, before they can sign in.', group: 'users')
        ->define(AccountSettings::PASSWORD_RESET, 'bool', false, label: 'Password reset', help: 'Whether users who forgot their password can choose a new one through an emailed link.', group: 'users')
        ->define(AccountSettings::MAIL_FROM, 'string', null, label: 'Email sender', help: 'Who emails come from, e.g. "Tournament news <news@example.com>". Empty for the site title and noreply@ the site\'s domain.', group: 'users', nullable: true)
        ->define(AuditLog::RETENTION_SETTING, 'int', 365, label: 'Keep the audit log', help: 'For how many days the audit log keeps who did what. 0 keeps it forever.', group: 'users')
        ->define(SignInListener::PASSWORD_LOGIN_SETTING, 'bool', true, label: 'Password sign-in', help: 'Whether users can sign in with a password. Turn it off when everyone signs in another way, e.g. with single sign-on.', group: 'users')
        // Read when the application is built: changing it rebuilds the application (quire:theme:use does that right away).
        ->define(Themes::SETTING, 'string', Themes::DEFAULT, label: 'Theme', help: 'The ID of the site\'s theme, e.g. "acme-dark". Empty for Quire\'s built-in templates.', group: 'appearance', nullable: true),

    // Visitors are guests; users have one or more of these roles. Extensions can add more.
    new Extend\Permissions()
        ->role(Role::MEMBER, 'Member')
        ->role(Role::EDITOR, 'Editor')
        ->role(Role::ADMIN, 'Admin')
        // The pages of the admin interface, whichever extension adds them (routes in the group "admin").
        ->ability(AdminAccessListener::ABILITY, 'Use the admin interface', roles: [Role::EDITOR], group: 'admin')
        // Site management: admins only (they have every ability), unless granted to other roles.
        ->ability('quire.settings.manage', 'Change the site\'s settings', roles: [], group: 'admin')
        ->ability('quire.themes.manage', 'Change the site\'s theme', roles: [], group: 'admin')
        ->ability('quire.extensions.manage', 'Enable, disable and purge extensions', roles: [], group: 'admin')
        ->ability('quire.users.manage', 'Add, change, suspend and delete users', roles: [], group: 'admin')
        ->ability('quire.permissions.manage', 'Change roles and what they may do', roles: [], group: 'admin')
        ->ability(AuditLog::ABILITY, 'See the audit log: who did what, and when', roles: [], group: 'admin'),

    // What people do with the site: admin interfaces, APIs and the audit log go through them (see docs/extensions.md#extendactions).
    new Extend\Actions()
        ->action('content.create', ContentAction\CreateContent::class)
        ->action('content.update', ContentAction\UpdateContent::class)
        ->action('content.transition', ContentAction\TransitionContent::class)
        ->action('content.trash', ContentAction\TrashContent::class)
        ->action('content.restore', ContentAction\RestoreContent::class)
        ->action('content.purge', ContentAction\PurgeContent::class)
        ->action('content.submit_changes', ContentAction\SubmitChanges::class)
        ->action('content.review_changes', ContentAction\ReviewChanges::class)
        ->action('content.schedule_changes', ContentAction\ScheduleChanges::class)
        ->action('content.publish_changes', ContentAction\PublishChanges::class)
        ->action('content.discard_changes', ContentAction\DiscardChanges::class)
        ->action('content.comment_changes', ContentAction\CommentOnChanges::class)
        ->action('content.resolve_comment', ContentAction\ResolveComment::class)
        ->action('user.create', UserAction\CreateUser::class)
        ->action('user.invite', UserAction\InviteUsers::class)
        ->action('user.update', UserAction\UpdateUser::class)
        ->action('user.change_roles', UserAction\ChangeRoles::class)
        ->action('user.suspend', UserAction\SuspendUser::class)
        ->action('user.activate', UserAction\ActivateUser::class)
        ->action('user.set_password', UserAction\SetPassword::class)
        ->action('user.remove_password', UserAction\RemovePassword::class)
        ->action('user.delete', UserAction\DeleteUser::class)
        ->action('role.create', PermissionAction\CreateRole::class)
        ->action('role.rename', PermissionAction\RenameRole::class)
        ->action('role.delete', PermissionAction\DeleteRole::class)
        ->action('role.grant', PermissionAction\GrantAbilities::class)
        ->action('role.revoke', PermissionAction\RevokeAbilities::class)
        ->action('setting.set', SetSetting::class)
        ->action('theme.use', UseTheme::class)
        ->action('extension.enable', ExtensionAction\EnableExtensions::class)
        ->action('extension.disable', ExtensionAction\DisableExtensions::class)
        ->action('extension.purge', ExtensionAction\PurgeExtensions::class),

    new Extend\Formatter()
        ->format('markdown', Format\MarkdownFormat::class)
        ->format('text', Format\TextFormat::class)
        ->format('html', Format\HtmlFormat::class)
        // Content written by editors.
        ->profile('content', formats: ['markdown', 'text', 'html'], shortcodes: ['*'], headingAnchors: true)
        // Text written by visitors, e.g. comments: basic markup, no images, links marked as user-generated.
        ->profile('comment', formats: ['markdown', 'text'], sanitizer: 'basic', images: false, linkRel: ['nofollow', 'ugc']),

    // Tasks of core that run on a schedule, in the background (see docs/jobs.md).
    new Extend\Jobs()
        ->schedule('quire.publish-due', 'every 1 minute', PublishDueContent::class)
        ->schedule('quire.collect-garbage', '30 3 * * *', CollectGarbage::class)
        ->schedule('quire.prune-audit-log', '45 3 * * *', PruneAuditLog::class)
        ->schedule('quire.recheck-content', '15 4 * * *', RecheckContent::class),

    new Extend\ContentWorkflow()
        ->status(Status::DRAFT)
        ->status(Status::SCHEDULED)
        ->status(Status::PUBLISHED)
        ->status(Status::ARCHIVED)
        ->transition(Status::PUBLISH, from: [Status::DRAFT, Status::SCHEDULED, Status::ARCHIVED], to: Status::PUBLISHED)
        ->transition(Status::SCHEDULE, from: [Status::DRAFT, Status::SCHEDULED], to: Status::SCHEDULED)
        ->transition(Status::UNPUBLISH, from: [Status::PUBLISHED, Status::SCHEDULED, Status::ARCHIVED], to: Status::DRAFT)
        ->transition(Status::ARCHIVE, from: [Status::PUBLISHED], to: Status::ARCHIVED),

    new Extend\ContentType('post')
        ->label('Post', 'Posts')
        ->capabilities(Capability::REVISIONS, Capability::SCHEDULING, Capability::EXCERPT, Capability::LISTABLE, Capability::SEARCHABLE, Capability::AUTHOR)
        ->permalink('/blog/{slug}')
        ->sortBy('published_at', 'desc'),

    new Extend\ContentType('page')
        ->label('Page', 'Pages')
        ->capabilities(Capability::HIERARCHICAL, Capability::REVISIONS, Capability::SEARCHABLE, Capability::AUTHOR)
        ->permalink('/{path}')
        ->sortBy('position'),

    // Relations every query can load with ->with(...).
    new Extend\Query()
        ->relation('parent', Relation\ParentLoader::class)
        ->relation('children', Relation\ChildrenLoader::class)
        ->relation('translations', Relation\TranslationsLoader::class),
];
