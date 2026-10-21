<?php

declare(strict_types=1);

use Quire\Content\Type\Capability;
use Quire\Extend;

/*
 * Quire core uses the same extenders as extensions. This file runs first, before any extension.
 */
return [
    new Extend\Migrations('Quire\\Migrations', __DIR__.'/migrations'),

    new Extend\Settings()
        ->define('site.title', 'string', 'Quire', label: 'Site title', help: 'Shown in the browser tab and in the site header.', group: 'site')
        ->define('site.description', 'string', '', label: 'Site description', help: 'A short description of the site, used by search engines and when the site is shared.', group: 'site', widget: 'textarea'),

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
