<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * One instance of each field type, made on first use. Types keep no state, so one instance serves every
 * request of a worker.
 *
 * @internal
 */
final class FieldTypeInstances
{
    /** @var array<class-string<FieldTypeInterface>, FieldTypeInterface> */
    private static array $instances = [];

    /**
     * @param class-string<FieldTypeInterface> $class
     */
    public static function get(string $class): FieldTypeInterface
    {
        return self::$instances[$class] ??= new $class();
    }

    private function __construct()
    {
    }
}
