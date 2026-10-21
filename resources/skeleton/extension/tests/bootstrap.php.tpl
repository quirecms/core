<?php

declare(strict_types=1);

// Finds Composer's autoloader both in the extension's own repository and inside a site
// (where the extension lives in extensions/ and the site's vendor/ is further up).
for ($dir = __DIR__; dirname($dir) !== $dir; $dir = dirname($dir)) {
    if (is_file($dir.'/vendor/autoload.php')) {
        require $dir.'/vendor/autoload.php';

        return;
    }
}

throw new RuntimeException('Composer\'s autoloader was not found. Run "composer install".');
