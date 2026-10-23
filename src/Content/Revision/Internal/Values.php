<?php

declare(strict_types=1);

namespace Quire\Content\Revision\Internal;

/**
 * @internal
 */
final class Values
{
    /**
     * Strict equality, except that dates compare by instant and fields ignore key order (as JSONB does).
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return match (true) {
            $a instanceof \DateTimeImmutable && $b instanceof \DateTimeImmutable => $a->getTimestamp() === $b->getTimestamp(),
            \is_array($a) && \is_array($b) => self::sorted($a) === self::sorted($b),
            default => $a === $b,
        };
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed> With object keys sorted, recursively
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn (mixed $item): mixed => \is_array($item) ? self::sorted($item) : $item, $value);
    }
}
