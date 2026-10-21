<?php

declare(strict_types=1);

use Quire\Extend;

/*
 * Quire core uses the same extenders as extensions. This file runs first, before any extension.
 */
return [
    new Extend\Settings()
        ->define('site.title', 'string', 'Quire', label: 'Site title', help: 'Shown in the browser tab and in the site header.', group: 'site')
        ->define('site.description', 'string', '', label: 'Site description', help: 'A short description of the site, used by search engines and when the site is shared.', group: 'site', widget: 'textarea'),
];
