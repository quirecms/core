<?php

declare(strict_types=1);

use Quire\Content\Query\Relation;
use Quire\Content\Status;
use Quire\Content\Type\Capability;
use Quire\Extend;
use Quire\Formatter\Format;
use Quire\Theme\Themes;

/*
 * Quire core uses the same extenders as extensions. This file runs first, before any extension.
 */
return [
    new Extend\Migrations('Quire\\Migrations', __DIR__.'/migrations'),

    new Extend\Settings()
        ->define('site.title', 'string', 'Quire', label: 'Site title', help: 'Shown in the browser tab and in the site header.', group: 'site', exposed: true)
        ->define('site.description', 'string', '', label: 'Site description', help: 'A short description of the site, used by search engines and when the site is shared.', group: 'site', widget: 'textarea', exposed: true)
        ->define('site.locale', 'string', 'en', label: 'Language', help: 'The language of the site and of new content, as a language tag such as "en", "de" or "pt-BR".', group: 'site', exposed: true)
        ->define('quire.content.revisions_keep', 'int', 50, label: 'Revisions to keep', help: 'How many revisions to keep per content item. Older ones are deleted. 0 keeps all.', group: 'content')
        ->define('quire.content.per_page', 'int', 10, label: 'Items per page', help: 'How many items lists such as the blog show per page (1 to 100).', group: 'content')
        // What the homepage shows. The URL patterns of each content type are settings too: quire.permalinks.<type>.
        ->define('site.homepage', 'enum', 'listing', label: 'Homepage', help: 'What the homepage shows: the list of a content type (see "Homepage list"), a page (see "Homepage page"), or a page of an extension (see "Homepage route").', group: 'site', choices: ['listing', 'page', 'route'])
        ->define('site.homepage_listing', 'string', 'post', label: 'Homepage list', help: 'The content type whose list the homepage shows, e.g. "post".', group: 'site')
        ->define('site.homepage_page', 'string', null, label: 'Homepage page', help: 'The page the homepage shows: its ID or its path, e.g. "home".', group: 'site', nullable: true)
        ->define('site.homepage_route', 'string', null, label: 'Homepage route', help: 'The name of the extension route the homepage shows. It must not need parameters.', group: 'site', nullable: true)
        // Read when the application is built: changing it rebuilds the application (quire:theme:use does that right away).
        ->define(Themes::SETTING, 'string', Themes::DEFAULT, label: 'Theme', help: 'The ID of the site\'s theme, e.g. "acme-dark". Empty for Quire\'s built-in templates.', group: 'appearance', nullable: true),

    new Extend\Formatter()
        ->format('markdown', Format\MarkdownFormat::class)
        ->format('text', Format\TextFormat::class)
        ->format('html', Format\HtmlFormat::class)
        // Content written by editors.
        ->profile('content', formats: ['markdown', 'text', 'html'], shortcodes: ['*'], headingAnchors: true)
        // Text written by visitors, e.g. comments: basic markup, no images, links marked as user-generated.
        ->profile('comment', formats: ['markdown', 'text'], sanitizer: 'basic', images: false, linkRel: ['nofollow', 'ugc']),

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
