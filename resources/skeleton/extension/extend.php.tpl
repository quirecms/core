<?php

declare(strict_types=1);

use @@NAMESPACE@@\Controller\MessagesController;
use Quire\Extend;

/*
 * Everything @@TITLE@@ adds to Quire. See https://github.com/quirecms/framework/blob/main/docs/extensions.md for all extenders.
 */
return [
    // Registers every class in src/ as a service, so dependencies are injected.
    new Extend\Services()
        ->autoload('@@NAMESPACE_ESCAPED@@\\', __DIR__.'/src'),

    new Extend\Routes()
        ->get('@@PATH@@', '@@ROUTE@@', MessagesController::class),

    // Templates in templates/ are available as "@@@TWIG_NAMESPACE@@/...".
    new Extend\Views()
        ->namespace('@@TWIG_NAMESPACE@@', __DIR__.'/templates'),

    // Change it with: bin/console quire:settings:set @@ID@@.greeting "Welcome!"
    new Extend\Settings()
        ->define('@@ID@@.greeting', 'string', 'Hello from @@TITLE@@!', label: 'Greeting', help: 'The heading of the @@TITLE@@ page.'),

    // Run when the extension is enabled, rolled back by "quire:extension:disable @@ID@@ --purge".
    new Extend\Migrations('@@NAMESPACE_ESCAPED@@\\Migrations', __DIR__.'/migrations'),
];
