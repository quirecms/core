<?php

declare(strict_types=1);

use Quire\Content\Status;
use Quire\Content\Type\Capability;
use Quire\Extend;

/*
 * Quire core uses the same extenders as extensions. This file runs first, before any extension.
 */
return [
    new Extend\Migrations('Quire\\Migrations', __DIR__.'/migrations'),

    new Extend\Settings()
        ->define('site.title', 'string', 'Quire', label: 'Site title', help: 'Shown in the browser tab and in the site header.', group: 'site')
        ->define('site.description', 'string', '', label: 'Site description', help: 'A short description of the site, used by search engines and when the site is shared.', group: 'site', widget: 'textarea')
        ->define('site.locale', 'string', 'en', label: 'Language', help: 'The language of the site and of new content, as a language tag such as "en", "de" or "pt-BR".', group: 'site')
        ->define('quire.content.revisions_keep', 'int', 50, label: 'Revisions to keep', help: 'How many revisions to keep per content item. Older ones are deleted. 0 keeps all.', group: 'content'),

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
];
