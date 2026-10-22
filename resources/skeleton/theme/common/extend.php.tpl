<?php

declare(strict_types=1);

use Quire\Extend;

/*
 * The @@TITLE@@ theme. Runs while it's the site's theme. See docs/themes.md for what themes can do.
 */
return [
    // assets/styles.css, on every page.
    new Extend\Assets()
        ->stylesheet('@@ID@@/styles.css'),
];
