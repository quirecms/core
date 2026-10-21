<?php

declare(strict_types=1);

namespace Quire\Extension\Exception;

/**
 * An installed package declares an invalid `extra.quire` manifest.
 *
 * @api
 */
final class InvalidManifestException extends \RuntimeException
{
    public static function forPackage(string $packageName, string $problem): self
    {
        return new self(\sprintf('[%s] Invalid Quire manifest in composer.json: %s', $packageName, $problem));
    }
}
