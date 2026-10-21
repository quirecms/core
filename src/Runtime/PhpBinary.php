<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Locates the PHP command-line binary, to run console commands in new processes.
 *
 * PHP_BINARY can't be used for that from a web request: under FrankenPHP or php-fpm it points to the
 * server binary, not to a CLI that runs scripts.
 */
final class PhpBinary
{
    public static function path(): string
    {
        if (\PHP_SAPI === 'cli') {
            return \PHP_BINARY;
        }

        $path = new PhpExecutableFinder()->find(false);

        if (false === $path) {
            throw new \RuntimeException('Can\'t find the PHP command-line binary. Make sure "php" is on the PATH.');
        }

        return $path;
    }
}
